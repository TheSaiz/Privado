<?php
// =========================================
// CORS DINÁMICO APP + WEB (PROD ESTABLE)
// =========================================

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

$allowedOrigins = [
    'https://prestamolider.com'
];

if ($origin && in_array($origin, $allowedOrigins, true)) {
    // WEB (usa sesión / cookies)
    header("Access-Control-Allow-Origin: $origin");
    header("Access-Control-Allow-Credentials: true");
} else {
    // APP Android / iOS o llamadas server-to-server
    header("Access-Control-Allow-Origin: *");
}

header("Vary: Origin");
header("Content-Type: application/json; charset=utf-8");
header("Access-Control-Allow-Methods: POST, GET, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With");

// Preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$rawInput = file_get_contents("php://input");
$data = json_decode($rawInput, true);
$dni = trim($data["dni"] ?? "");

if (!$dni) {
    echo json_encode(["success" => false, "message" => "El DNI es obligatorio"]);
    exit;
}

$dni = preg_replace('/\D/', '', $dni);

if (strlen($dni) < 7 || strlen($dni) > 8) {
    echo json_encode(["success" => false, "message" => "El DNI debe tener entre 7 y 8 dígitos"]);
    exit;
}

// =========================================
// 1️⃣ ALGORITMO CUIL REAL
// =========================================
function calcularCUIT($dni, $prefijo)
{
    $base = $prefijo . $dni;
    if (strlen($base) !== 10) return null;

    $multi = [5,4,3,2,7,6,5,4,3,2];
    $suma = 0;

    for ($i = 0; $i < 10; $i++) {
        $suma += (int)$base[$i] * $multi[$i];
    }

    $resto  = $suma % 11;
    $digito = 11 - $resto;

    if ($digito == 11) $digito = 0;
    if ($digito == 10) return null;

    return $base . $digito;
}

// =========================================
// CONFIG SOAP
// =========================================
$wsdl = "http://padronbcra.dyndns.info:9999/aconsultapaws.aspx?wsdl";

$options = [
    "trace" => 0,
    "exceptions" => 0,
    "cache_wsdl" => WSDL_CACHE_NONE
];

$client = new SoapClient($wsdl, $options);

// =========================================
// 2️⃣ PRUEBA CUIT OFICIAL
// =========================================
$prefijos = [20, 23, 24, 27, 30];
$resultado = null;

foreach ($prefijos as $p) {

    $cuit = calcularCUIT($dni, $p);
    if (!$cuit) continue;

    $xml = '<GX xmlns="GX">
                <Usuario>P.LIDER</Usuario>
                <Clave>P.LIDER</Clave>
                <Version>1</Version>
                <Pa_Tipo>CUIT</Pa_Tipo>
                <CUIT>' . $cuit . '</CUIT>
            </GX>';

    $params = ["Parametros" => $xml];
    $response = $client->__soapCall("Execute", [$params]);

    if (isset($response->Bcra_rpa) && intval($response->Bcra_rpa->Casos) > 0) {
        $resultado = $response->Bcra_rpa;
        break;
    }
}

// =========================================
// 3️⃣ SI NO FUNCIONA → LEGACY
// =========================================
if (!$resultado) {

    $dni_legacy = str_pad($dni, 8, '0', STR_PAD_LEFT);
    $dni_legacy = "00{$dni_legacy}0";

    $xmlLegacy = '<GX xmlns="GX">
<Usuario>P.LIDER</Usuario>
<Clave>P.LIDER</Clave>
<Version>1</Version>
<CUIT>' . $dni_legacy . '</CUIT>
</GX>';

    $parametros = "[PA_CUIT][" . $xmlLegacy . "]";
    $params = ["Parametros" => $parametros];

    $response = $client->__soapCall("Execute", [$params]);

    if (isset($response->Bcra_rpa) && intval($response->Bcra_rpa->Casos) > 0) {
        $resultado = $response->Bcra_rpa;
    }
}

// =========================================
// 4️⃣ SIN RESULTADOS
// =========================================
if (!$resultado) {
    echo json_encode([
        "success" => false,
        "message" => "No se encontró información para el DNI ingresado."
    ]);
    exit;
}

// =========================================
// 5️⃣ PROCESAR RESULTADO
// =========================================
$casos = intval($resultado->Casos);

if ($casos === 1) {
    $item = $resultado->Datos->Item;

    echo json_encode([
        "success" => true,
        "casos" => 1,
        "cuil"  => trim($item->PaCUIT),
        "nombre"=> trim($item->PaDenom ?: "Cliente Desconocido")
    ]);
    exit;
}

$items = is_array($resultado->Datos->Item)
    ? $resultado->Datos->Item
    : [$resultado->Datos->Item];

$opciones = [];

foreach ($items as $it) {
    $opciones[] = [
        "cuil" => trim($it->PaCUIT),
        "nombre" => trim($it->PaDenom ?: "Cliente Desconocido")
    ];
}

echo json_encode([
    "success" => true,
    "casos" => count($opciones),
    "opciones" => $opciones
]);
exit;
?>