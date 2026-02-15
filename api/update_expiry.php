<?php
require_once 'db.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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
    // Buscar registro atual
    $stmt = $conn->prepare("SELECT id, expiry_date, validation FROM registrations WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();
    $registration = $result->fetch_assoc();
    $stmt->close();

    if (!$registration) {
        http_response_code(404);
        echo json_encode(["error" => "Registro não encontrado"]);
        exit;
    }

    // Calcular nova data de validade
    // Se ainda tem dias restantes, soma os meses à data de expiração atual
    // Se já expirou, soma os meses a partir de hoje
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
    $updateStmt->bind_param("si", $newExpiry, $id);
    $success = $updateStmt->execute();
    $updateStmt->close();

    if ($success) {
        echo json_encode([
            "success" => true,
            "message" => "Validade reativada com sucesso",
            "data" => [
                "id" => $id,
                "new_expiry_date" => $newExpiry,
                "months_added" => $months,
                "validation" => "valid"
            ]
        ]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Erro ao atualizar registro"]);
    }

} catch (Exception $e) {
    $log_path = __DIR__ . "/error.log";
    error_log(date('[Y-m-d H:i:s] ') . "Update expiry error: " . $e->getMessage() . "\n", 3, $log_path);

    http_response_code(500);
    echo json_encode(["error" => "Erro interno do servidor"]);
}

$conn->close();
?>
