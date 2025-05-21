<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With');

ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', 'php_errors.log');

require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

$dbConfig = [
    'servername' => $_ENV['DB_HOST'],
    'username' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASSWORD'],
    'dbname' => $_ENV['DB_NAME']
];

function sendJsonResponse($success, $message = '', $data = [], $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data' => $data
    ]);
    exit();
}

try {
    $conn = new mysqli(
        $dbConfig['servername'],
        $dbConfig['username'],
        $dbConfig['password'],
        $dbConfig['dbname']
    );
    
    if ($conn->connect_error) {
        throw new Exception("Error de conexión MySQL: " . $conn->connect_error);
    }
    
    $conn->set_charset("utf8mb4");
    
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        sendJsonResponse(false, 'Método no permitido', [], 405);
    }
    
    $jsonInput = file_get_contents('php://input');
    $data = json_decode($jsonInput, true);
    
    $requiredFields = [
        'employeeNumber', 'department', 'company', 'email',
        'vacationStartDate', 'vacationEndDate', 'daysRequested',
        'supervisorName', 'supervisorEmail', 'comments'
    ];
    
    foreach ($requiredFields as $field) {
        if (!isset($data[$field]) || 
            (is_string($data[$field]) && trim($data[$field]) === '') || 
            $data[$field] === null) {
            sendJsonResponse(false, "El campo $field es requerido", [], 400);
        }
    }
    
    $checkStmt = $conn->prepare("SELECT numero_empleado, nombre_completo, dias_restantes FROM vacaciones_empleados WHERE numero_empleado = ?");
    $checkStmt->bind_param("i", $data['employeeNumber']);
    $checkStmt->execute();
    $result = $checkStmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception("Empleado no registrado en el sistema");
    }
    
    $empleado = $result->fetch_assoc();
    $nombreEmpleado = $empleado['nombre_completo'];
    $diasRestantes = (int)$empleado['dias_restantes'];
    $diasSolicitados = (int)$data['daysRequested'];
    
    if ($diasRestantes < $diasSolicitados) {
        sendJsonResponse(false, "No tiene suficientes días de vacaciones disponibles. Días restantes: $diasRestantes", [
            'dias_restantes' => $diasRestantes,
            'dias_solicitados' => $diasSolicitados
        ], 400);
    }
    
    $insertStmt = $conn->prepare("INSERT INTO solicitudes_vacaciones (
        nombre, numero_empleado, departamento, razon_social, email,
        fecha_inicio, fecha_fin, dias_solicitados, nombre_supervisor,
        email_supervisor, comentarios, status
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pendiente')");
    
    $insertStmt->bind_param(
        "sisssssisss",
        $nombreEmpleado,
        $data['employeeNumber'],
        $data['department'],
        $data['company'],
        $data['email'],
        $data['vacationStartDate'],
        $data['vacationEndDate'],
        $diasSolicitados,
        $data['supervisorName'],
        $data['supervisorEmail'],
        $data['comments']
    );
    
    if (!$insertStmt->execute()) {
        throw new Exception("Error al registrar solicitud: " . $insertStmt->error);
    }
    
    $solicitudId = $insertStmt->insert_id;
    
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = $_ENV['SMTP_HOST'];
        $mail->SMTPAuth = true;
        $mail->Username = $_ENV['SMTP_USER'];
        $mail->Password = $_ENV['SMTP_PASSWORD'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port = $_ENV['SMTP_PORT'];
        $mail->SMTPOptions = [
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true
            ]
        ];
        
        $mail->setFrom($_ENV['SMTP_FROM'], $_ENV['SMTP_FROM_NAME']);
        $mail->addAddress($data['supervisorEmail'], $data['supervisorName']);
        $mail->addReplyTo($data['email'], $nombreEmpleado);
        $mail->isHTML(true);
        $mail->Subject = 'Solicitud de Vacaciones - ' . $nombreEmpleado;
        
        $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'];
        $baseUrl = $_ENV['APP_URL'] ?? "$protocol://$host/imperquimiaVacaciones";
        $approveLink = "$baseUrl/backend/accion_solicitud.php?action=approve&id=$solicitudId";
        $rejectLink = "$baseUrl/backend/accion_solicitud.php?action=reject&id=$solicitudId";
        
        $mail->Body = "
            <h2>Solicitud de Vacaciones</h2>
            <p>Hola {$data['supervisorName']},</p>
            <p>Tienes una nueva solicitud de vacaciones que requiere tu revisión:</p>
            
            <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                <p><strong>Empleado:</strong> {$nombreEmpleado} (#{$data['employeeNumber']})</p>
                <p><strong>Departamento:</strong> {$data['department']}</p>
                <p><strong>Periodo solicitado:</strong> {$data['vacationStartDate']} al {$data['vacationEndDate']}</p>
                <p><strong>Días solicitados:</strong> {$diasSolicitados} días</p>
                <p><strong>Días disponibles:</strong> {$diasRestantes} días</p>
            </div>
            
            <p>Por favor, revisa esta solicitud y toma una acción:</p>
            <div style='margin: 20px 0;'>
                <a href='{$approveLink}' style='background: #4CAF50; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; margin-right: 10px;'>Aprobar Solicitud</a>
                <a href='{$rejectLink}' style='background: #f44336; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Rechazar Solicitud</a>
            </div>
        ";
        
        $mail->send();
        
    } catch (Exception $e) {
        error_log("Error al enviar correo: " . $e->getMessage());
    }
    
    sendJsonResponse(true, 'Solicitud registrada correctamente', [
        'id' => $solicitudId,
        'employee' => $data['employeeNumber'],
        'nombre_empleado' => $nombreEmpleado,
        'dias_restantes' => $diasRestantes - $diasSolicitados
    ]);
    
} catch (Exception $e) {
    error_log("Error: " . $e->getMessage());
    sendJsonResponse(false, $e->getMessage(), [], 500);
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}