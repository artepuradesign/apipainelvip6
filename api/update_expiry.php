<?php
// update_expiry.php - Reativar validade de QR Code RG
// Conecta ao banco u617342185_qrcode (separado do banco principal)

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, Accept');

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);

if (!$input) {
    http_response_code(400);
    echo json_encode(["error" => "JSON inválido"]);
    exit;
}

$id = $input['id'] ?? null;
$months = $input['months'] ?? null;

if (!$id || !$months) {
    http_response_code(400);
    echo json_encode(["error" => "Parâmetros 'id' e 'months' são obrigatórios"]);
    exit;
}

$months = intval($months);
if (!in_array($months, [1, 3, 6])) {
    http_response_code(400);
    echo json_encode(["error" => "Período inválido. Use 1, 3 ou 6 meses"]);
    exit;
}

try {
    // Conexão PDO ao banco de QR Codes
    $dsn = "mysql:host=45.151.120.2;dbname=u617342185_qrcode;charset=utf8mb4";
    $conn = new PDO($dsn, 'u617342185_userapipainel2', 'Acerola@2025', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Buscar registro atual
    $stmt = $conn->prepare("SELECT id, expiry_date, validation FROM registrations WHERE id = ?");
    $stmt->execute([$id]);
    $registration = $stmt->fetch();

    if (!$registration) {
        http_response_code(404);
        echo json_encode(["error" => "Registro não encontrado"]);
        exit;
    }

    // Calcular nova data de validade (cumulativa)
    $currentExpiry = strtotime($registration['expiry_date']);
    $now = time();
    
    if ($currentExpiry > $now) {
        // Ainda tem validade: adicionar meses à data atual de expiração
        $baseDate = $registration['expiry_date'];
    } else {
        // Já expirou: adicionar meses a partir de hoje
        $baseDate = date('Y-m-d H:i:s');
    }
    
    $newExpiry = date('Y-m-d H:i:s', strtotime("+{$months} months", strtotime($baseDate)));

    // Atualizar data de validade e reativar validação
    $updateStmt = $conn->prepare("UPDATE registrations SET expiry_date = ?, validation = 'valid' WHERE id = ?");
    $success = $updateStmt->execute([$newExpiry, $id]);

    if ($success) {
        echo json_encode([
            "success" => true,
            "message" => "Validade reativada com sucesso",
            "data" => [
                "id" => $id,
                "new_expiry_date" => $newExpiry,
                "months_added" => $months,
                "validation" => "valid",
                "previous_expiry" => $registration['expiry_date'],
                "cumulative" => ($currentExpiry > $now)
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao atualizar registro"]);
    }

} catch (Exception $e) {
    error_log(date('[Y-m-d H:i:s] ') . "Update expiry error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(["error" => "Erro interno do servidor: " . $e->getMessage()]);
}
?>
