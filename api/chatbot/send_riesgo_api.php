<?php
/**
 * =========================================================
 * ENVÍO RIESGO – PRODUCCIÓN FINAL
 * Adaptado 100% a la API original que funciona
 * =========================================================
 */

function enviarDatosRiesgo(int $chatId): bool
{
    global $pdo;

    if (!$pdo) {
        require_once __DIR__ . "/../../backend/connection.php";
    }

    // LOCK ANTI-DUPLICADO
    $stmt = $pdo->prepare("
        UPDATE chats
        SET api_enviado = 2
        WHERE id = ?
          AND api_enviado = 0
    ");
    $stmt->execute([$chatId]);

    if ($stmt->rowCount() !== 1) {
        return false;
    }

    // OBTENER DATOS FINALES
    $stmt = $pdo->prepare("
        SELECT
            c.id,
            c.cuil_validado,
            c.nombre_validado,
            c.banco,
            c.situacion_laboral,
            u.email,
            u.telefono
        FROM chats c
        INNER JOIN usuarios u ON u.id = c.cliente_id
        WHERE c.id = ?
        LIMIT 1
    ");
    $stmt->execute([$chatId]);
    $d = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$d) {
        $pdo->prepare("UPDATE chats SET api_enviado = 0 WHERE id = ?")->execute([$chatId]);
        throw new Exception("Chat no encontrado");
    }

    // VALIDAR CAMPOS REQUERIDOS
    $camposRequeridos = ['cuil_validado', 'nombre_validado', 'email', 'telefono', 'banco', 'situacion_laboral'];
    
    foreach ($camposRequeridos as $campo) {
        if (empty($d[$campo])) {
            $pdo->prepare("UPDATE chats SET api_enviado = 0 WHERE id = ?")->execute([$chatId]);
            throw new Exception("Datos incompletos: falta {$campo}");
        }
    }

    // NORMALIZAR TELÉFONO
    $telefono = preg_replace('/\D+/', '', $d['telefono']);

    if (strlen($telefono) < 6) {
        $pdo->prepare("UPDATE chats SET api_enviado = 0 WHERE id = ?")->execute([$chatId]);
        throw new Exception("Teléfono inválido");
    }

    // SEPARAR CÓDIGO DE ÁREA Y TELÉFONO (lógica simple como la API original)
    $areacode = substr($telefono, 0, min(4, strlen($telefono) - 6));
    $phone = substr($telefono, strlen($areacode));

    // MAPEO DE CÓDIGOS DE ÁREA (COMPLETO - igual que la API original)
    $codigos = [
        "11" => "Ciudad Autónoma de Buenos Aires y Gran Buenos Aires",
        "220" => "San Miguel - Buenos Aires",
        "221" => "La Plata - Buenos Aires",
        "223" => "Mar del Plata - Buenos Aires",
        "230" => "Campana - Buenos Aires",
        "236" => "Junín - Buenos Aires",
        "237" => "Moreno - Buenos Aires",
        "249" => "Tandil - Buenos Aires",
        "260" => "General Alvear - Mendoza",
        "261" => "Mendoza - Mendoza",
        "263" => "San Martín - Mendoza",
        "264" => "San Juan - San Juan",
        "266" => "San Luis - San Luis",
        "280" => "Rawson - Chubut",
        "291" => "Bahía Blanca - Buenos Aires",
        "294" => "Bariloche - Río Negro",
        "297" => "Comodoro Rivadavia - Chubut",
        "298" => "Tres Arroyos - Buenos Aires",
        "299" => "Neuquén - Neuquén",
        "336" => "San Nicolás de los Arroyos - Buenos Aires",
        "341" => "Rosario - Santa Fe",
        "342" => "Santa Fe - Santa Fe",
        "343" => "Paraná - Entre Ríos",
        "345" => "Concordia - Entre Ríos",
        "348" => "San Pedro - Buenos Aires",
        "351" => "Córdoba - Córdoba",
        "353" => "Villa María - Córdoba",
        "358" => "Río Cuarto - Córdoba",
        "362" => "Resistencia - Chaco",
        "364" => "Presidencia Roque Sáenz Peña - Chaco",
        "370" => "Formosa - Formosa",
        "376" => "Posadas - Misiones",
        "379" => "Corrientes - Corrientes",
        "380" => "La Rioja - La Rioja",
        "381" => "San Miguel de Tucumán - Tucumán",
        "383" => "Catamarca - Catamarca",
        "385" => "Santiago del Estero - Santiago del Estero",
        "387" => "Salta - Salta",
        "388" => "San Salvador de Jujuy - Jujuy",
        "2202" => "Luján - Buenos Aires",
        "2221" => "Brandsen - Buenos Aires",
        "2223" => "Cañuelas - Buenos Aires",
        "2224" => "San Vicente - Buenos Aires",
        "2225" => "General Belgrano - Buenos Aires",
        "2226" => "Monte - Buenos Aires",
        "2227" => "Navarro - Buenos Aires",
        "2229" => "Roque Pérez - Buenos Aires",
        "2241" => "Dolores - Buenos Aires",
        "2242" => "Lezama - Buenos Aires",
        "2243" => "Castelli - Buenos Aires",
        "2244" => "General Guido - Buenos Aires",
        "2245" => "Maipú - Buenos Aires",
        "2246" => "General Madariaga - Buenos Aires",
        "2252" => "Pinamar - Buenos Aires",
        "2254" => "Villa Gesell - Buenos Aires",
        "2255" => "Mar de Ajó - Buenos Aires",
        "2257" => "San Clemente del Tuyú - Buenos Aires",
        "2261" => "Necochea - Buenos Aires",
        "2262" => "Lobería - Buenos Aires",
        "2264" => "Balcarce - Buenos Aires",
        "2265" => "Rauch - Buenos Aires",
        "2266" => "Ayacucho - Buenos Aires",
        "2267" => "Benito Juárez - Buenos Aires",
        "2268" => "Tandil (Interior) - Buenos Aires",
        "2271" => "25 de Mayo - Buenos Aires",
        "2272" => "Saladillo - Buenos Aires",
        "2273" => "General Alvear - Buenos Aires",
        "2274" => "Las Flores - Buenos Aires",
        "2281" => "Olavarría - Buenos Aires",
        "2283" => "Bolívar - Buenos Aires",
        "2284" => "Daireaux - Buenos Aires",
        "2285" => "Pehuajó - Buenos Aires",
        "2286" => "Carlos Casares - Buenos Aires",
        "2291" => "Coronel Suárez - Buenos Aires",
        "2292" => "Pigüé - Buenos Aires",
        "2296" => "Coronel Pringles - Buenos Aires",
        "2297" => "Tres Arroyos (interior) - Buenos Aires",
        "2302" => "Zárate - Buenos Aires",
        "2314" => "Pergamino - Buenos Aires",
        "2316" => "Arrecifes - Buenos Aires",
        "2317" => "Colón - Buenos Aires",
        "2318" => "Salto - Buenos Aires",
        "2320" => "Chacabuco - Buenos Aires",
        "2323" => "Chivilcoy - Buenos Aires",
        "2324" => "Bragado - Buenos Aires",
        "2325" => "9 de Julio - Buenos Aires",
        "2326" => "Carlos Casares (interior) - Buenos Aires",
        "2331" => "Lincoln - Buenos Aires",
        "2333" => "General Pinto - Buenos Aires",
        "2334" => "Arenales - Buenos Aires",
        "2335" => "Rojas - Buenos Aires",
        "2901" => "Ushuaia - Tierra del Fuego",
        "2902" => "Río Grande - Tierra del Fuego",
        "2903" => "Tolhuin - Tierra del Fuego",
        "2920" => "Coronel Dorrego - Buenos Aires",
        "2940" => "El Bolsón - Río Negro",
        "2962" => "Puerto Deseado - Santa Cruz",
        "3735" => "Chorotis - Chaco",
        "3755" => "Oberá – Provincia de Misiones",
        "3772" => "Paso de los Libres – Provincia de Corrientes",
        "3751" => "Eldorado – Provincia de Misiones",
        "3525" => "Colonia Caroya - Cordoba",
        "2626" => "Villa Antigua - Mendoza",
        "2658" => "Anchorena - San Luis",
        "2948" => "Andacollo - Neuquén",
        "3327" => "Benavídez - Buenos Aires",
        "3329" => "Baradero - Buenos Aires",
        "3382" => "Rufino - Santa Fe",
        "3387" => "Buchardo - Cordoba",
        "3388" => "Ameghino - Santa Fe"
    ];

    $localidad = $codigos[$areacode] ?? "Localidad desconocida";

    // CONFIGURAR SOAP (configuración idéntica a la API original)
    $wsdl = "http://plider.dyndns.info:8080/riesgohook1_plider_ws.aspx?wsdl";

    $options = [
        'trace' => 1,
        'exceptions' => true,
        'cache_wsdl' => WSDL_CACHE_NONE
    ];

    try {
        $client = new SoapClient($wsdl, $options);

        $params = [
            'D_usucod' => '998',
            'D_name' => $d['nombre_validado'],
            'D_lastname' => '',
            'D_email' => $d['email'],
            'D_dni' => $d['cuil_validado'],
            'D_areacode' => $areacode,
            'D_phone' => $phone,
            'D_bank' => $d['banco'],
            'D_typeofincome' => $d['situacion_laboral'],
            'D_amount' => 0,
            'D_amountdestination' => '',
            'D_urgency' => 'quietly',
            'D_saleschannel' => 'Chatbot',
            'D_version' => 'plider',
            'D_campaign' => 'plider',
            'D_provincia' => $localidad,
            'D_parametros' => ''
        ];

        // ENVIAR A SOAP
        $response = $client->Execute($params);

        // MARCAR COMO ENVIADO
        $pdo->prepare("
            UPDATE chats
            SET api_enviado = 1,
                api_respuesta = ?
            WHERE id = ?
        ")->execute([
            json_encode($response, JSON_UNESCAPED_UNICODE),
            $chatId
        ]);

        // LOG EXITOSO
        $pdo->prepare("
            INSERT INTO chatbot_api_logs
            (chat_id, endpoint, request_data, response_data, status_code)
            VALUES (?, ?, ?, ?, 200)
        ")->execute([
            $chatId,
            $wsdl,
            json_encode($params, JSON_UNESCAPED_UNICODE),
            json_encode($response, JSON_UNESCAPED_UNICODE)
        ]);

        return true;

    } catch (SoapFault $e) {
        // ERROR - RESETEAR PARA REINTENTO
        $pdo->prepare("UPDATE chats SET api_enviado = 0 WHERE id = ?")->execute([$chatId]);

        // LOG DE ERROR
        $pdo->prepare("
            INSERT INTO chatbot_api_logs
            (chat_id, endpoint, request_data, response_data, status_code)
            VALUES (?, ?, ?, ?, 500)
        ")->execute([
            $chatId,
            $wsdl,
            json_encode($params ?? [], JSON_UNESCAPED_UNICODE),
            json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE)
        ]);

        throw new Exception("Error SOAP: " . $e->getMessage());
    }
}