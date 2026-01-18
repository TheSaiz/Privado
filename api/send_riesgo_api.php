<?php
// =====================================================
// send_riesgo_api.php
// Ruta: prestamolider.com/system/api/send_riesgo_api.php
// Envía datos al CRM externo desde el chatbot
// =====================================================

// ✅ CARGAR CONEXIÓN A BD
// Desde: /system/api/send_riesgo_api.php
// Hasta: /backend/connection.php
$connection_path = __DIR__ . "/../../backend/connection.php";

if (file_exists($connection_path)) {
    require_once $connection_path;
}

/**
 * Función principal que envía datos al CRM
 * Llamada desde save_answer.php cuando se confirma el email
 */
function enviarDatosRiesgo($chat_id) {
    global $pdo;

    // LOG para debug
    $log_file = __DIR__ . '/send_riesgo_api.log';
    
    try {
        // =====================================================
        // 1) OBTENER TODOS LOS DATOS DEL CHAT
        // =====================================================
        $stmt = $pdo->prepare("
            SELECT 
                c.banco,
                c.situacion_laboral,
                c.cuil_validado,
                c.nombre_validado,
                u.email,
                u.telefono
            FROM chats c
            JOIN usuarios u ON u.id = c.cliente_id
            WHERE c.id = ?
            LIMIT 1
        ");
        $stmt->execute([$chat_id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$data) {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - ERROR: Chat $chat_id no encontrado\n", FILE_APPEND);
            return false;
        }

        // =====================================================
        // 2) OBTENER CÓDIGO DE ÁREA Y TELÉFONO POR SEPARADO
        // =====================================================
        // Pregunta 4 = código de área
        $stmt = $pdo->prepare("
            SELECT respuesta
            FROM chatbot_respuestas
            WHERE chat_id = ? AND pregunta_id = 4
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$chat_id]);
        $areacode = trim((string)$stmt->fetchColumn());
        
        // Pregunta 5 = teléfono (sin código de área)
        $stmt = $pdo->prepare("
            SELECT respuesta
            FROM chatbot_respuestas
            WHERE chat_id = ? AND pregunta_id = 5
            ORDER BY id DESC LIMIT 1
        ");
        $stmt->execute([$chat_id]);
        $phone = trim((string)$stmt->fetchColumn());

        // LOG: Datos obtenidos
        $log_data = [
            'chat_id' => $chat_id,
            'cuil' => $data['cuil_validado'],
            'nombre' => $data['nombre_validado'],
            'email' => $data['email'],
            'areacode' => $areacode,
            'phone' => $phone,
            'banco' => $data['banco'],
            'situacion_laboral' => $data['situacion_laboral']
        ];
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - DATOS OBTENIDOS:\n" . print_r($log_data, true) . "\n", FILE_APPEND);

        // =====================================================
        // 3) VALIDAR DATOS OBLIGATORIOS
        // =====================================================
        if (empty($data['cuil_validado']) || empty($data['nombre_validado']) || 
            empty($data['email']) || empty($areacode) || empty($phone) || 
            empty($data['banco']) || empty($data['situacion_laboral'])) {
            
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - ERROR: Faltan datos obligatorios\n", FILE_APPEND);
            return false;
        }

        // =====================================================
        // 4) MAPEO DE CÓDIGOS DE ÁREA A LOCALIDADES (ARRAY COMPLETO)
        // =====================================================
        $codigos = [
            "11" => "Ciudad Autónoma de Buenos Aires y Gran Buenos Aires",
            "220" => "San Miguel - Buenos Aires", "221" => "La Plata - Buenos Aires",
            "223" => "Mar del Plata - Buenos Aires", "230" => "Campana - Buenos Aires",
            "236" => "Junín - Buenos Aires", "237" => "Moreno - Buenos Aires",
            "249" => "Tandil - Buenos Aires", "260" => "General Alvear - Mendoza",
            "261" => "Mendoza - Mendoza", "263" => "San Martín - Mendoza",
            "264" => "San Juan - San Juan", "266" => "San Luis - San Luis",
            "280" => "Rawson - Chubut", "291" => "Bahía Blanca - Buenos Aires",
            "294" => "Bariloche - Río Negro", "297" => "Comodoro Rivadavia - Chubut",
            "298" => "Tres Arroyos - Buenos Aires", "299" => "Neuquén - Neuquén",
            "336" => "San Nicolás de los Arroyos - Buenos Aires", "341" => "Rosario - Santa Fe",
            "342" => "Santa Fe - Santa Fe", "343" => "Paraná - Entre Ríos",
            "345" => "Concordia - Entre Ríos", "348" => "San Pedro - Buenos Aires",
            "351" => "Córdoba - Córdoba", "353" => "Villa María - Córdoba",
            "358" => "Río Cuarto - Córdoba", "362" => "Resistencia - Chaco",
            "364" => "Presidencia Roque Sáenz Peña - Chaco", "370" => "Formosa - Formosa",
            "376" => "Posadas - Misiones", "379" => "Corrientes - Corrientes",
            "380" => "La Rioja - La Rioja", "381" => "San Miguel de Tucumán - Tucumán",
            "383" => "Catamarca - Catamarca", "385" => "Santiago del Estero - Santiago del Estero",
            "387" => "Salta - Salta", "388" => "San Salvador de Jujuy - Jujuy"
        ];

        $localidad = $codigos[$areacode] ?? "Localidad desconocida";

        // =====================================================
        // 5) CONFIGURAR Y LLAMAR AL WEBSERVICE SOAP
        // =====================================================
        $wsdl = "http://plider.dyndns.info:8080/riesgohook1_plider_ws.aspx?wsdl";

        $options = [
            'trace' => 1,
            'exceptions' => true,
            'cache_wsdl' => WSDL_CACHE_NONE,
        ];

        $client = new SoapClient($wsdl, $options);

        // ✅ ESTRUCTURA EXACTA que espera el CRM
        $params = [
            'D_usucod' => '998',
            'D_name' => $data['nombre_validado'],
            'D_lastname' => '',
            'D_email' => $data['email'],
            'D_dni' => $data['cuil_validado'],
            'D_areacode' => $areacode,
            'D_phone' => $phone,
            'D_bank' => $data['banco'],
            'D_typeofincome' => $data['situacion_laboral'],
            'D_amount' => 0,
            'D_amountdestination' => '',
            'D_urgency' => 'quietly',
            'D_saleschannel' => 'Web',
            'D_version' => 'plider',
            'D_campaign' => 'plider',
            'D_provincia' => $localidad,
            'D_parametros' => ''
        ];

        // LOG: Parámetros que se enviarán
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - PARAMETROS SOAP:\n" . print_r($params, true) . "\n", FILE_APPEND);

        // Llamar al webservice
        $response = $client->Execute($params);

        // LOG: Respuesta del webservice
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - RESPUESTA SOAP:\n" . print_r($response, true) . "\n\n", FILE_APPEND);

        // =====================================================
        // 6) PROCESAR RESPUESTA
        // =====================================================
        if (isset($response->Respuesta->P_OK) && strtoupper($response->Respuesta->P_OK) === 'S') {
            $mensaje = strtoupper(trim($response->Respuesta->P_Msj));
            
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - ÉXITO: $mensaje\n\n", FILE_APPEND);
            
            // Actualizar estado en la BD
            $pdo->prepare("UPDATE chats SET enviado_crm = 1, respuesta_crm = ? WHERE id = ?")
                ->execute([$mensaje, $chat_id]);
            
            return true;
        } else {
            file_put_contents($log_file, date('Y-m-d H:i:s') . " - ERROR: Respuesta negativa del CRM\n\n", FILE_APPEND);
            return false;
        }

    } catch (SoapFault $e) {
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - ERROR SOAP: " . $e->getMessage() . "\n\n", FILE_APPEND);
        return false;
    } catch (Exception $e) {
        file_put_contents($log_file, date('Y-m-d H:i:s') . " - ERROR: " . $e->getMessage() . "\n\n", FILE_APPEND);
        return false;
    }
}