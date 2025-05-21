<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$dbConfig = [
    'servername' => $_ENV['DB_HOST'],
    'username' => $_ENV['DB_USER'],
    'password' => $_ENV['DB_PASSWORD'],
    'dbname' => $_ENV['DB_NAME']
];

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

header('Content-Type: text/html; charset=utf-8');

$action = $_GET['action'] ?? '';
$id = (int)($_GET['id'] ?? 0);

if ($action !== 'register' || $id <= 0) {
    die('<h2>Error: Parámetros inválidos</h2>');
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
    
    $consulta = $conn->prepare("SELECT 
        sv.*, ve.dias_restantes, ve.dias_tomados, ve.dias_disponibles
        FROM solicitudes_vacaciones sv
        JOIN vacaciones_empleados ve ON sv.numero_empleado = ve.numero_empleado
        WHERE sv.id = ? AND sv.status = 'aprobado_supervisor'");
    $consulta->bind_param("i", $id);
    $consulta->execute();
    $resultado = $consulta->get_result();
    $solicitud = $resultado->fetch_assoc();
    
    if (!$solicitud) {
        throw new Exception("No se encontró la solicitud o no está aprobada por supervisor");
    }
    
    $conn->begin_transaction();
    
    try {
        // Actualizar estado de la solicitud
        $stmt = $conn->prepare("UPDATE solicitudes_vacaciones SET status = 'aprobado' WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // Actualizar días del empleado
        $nuevosDiasTomados = $solicitud['dias_tomados'] + $solicitud['dias_solicitados'];
        $nuevosDiasRestantes = $solicitud['dias_disponibles'] - $nuevosDiasTomados;
        
        $updateDias = $conn->prepare("UPDATE vacaciones_empleados 
                                    SET dias_tomados = ?, dias_restantes = ?
                                    WHERE numero_empleado = ?");
        $updateDias->bind_param("iii", $nuevosDiasTomados, $nuevosDiasRestantes, $solicitud['numero_empleado']);
        $updateDias->execute();
        
        // Notificar al empleado
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
            $mail->isHTML(true);
            
            $mail->setFrom($_ENV['SMTP_FROM'], $_ENV['SMTP_FROM_NAME']);
            $mail->addAddress($solicitud['email'], $solicitud['nombre']);
            $mail->Subject = 'Vacaciones Registradas - ' . $solicitud['nombre'];
            
            $mail->Body = "
                <h2>Vacaciones Registradas</h2>
                <p>Hola {$solicitud['nombre']},</p>
                <p>Tu solicitud de vacaciones ha sido registrada exitosamente:</p>
                
                <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                    <p><strong>Periodo:</strong> {$solicitud['fecha_inicio']} al {$solicitud['fecha_fin']}</p>
                    <p><strong>Días:</strong> {$solicitud['dias_solicitados']}</p>
                    <p><strong>Días restantes:</strong> {$nuevosDiasRestantes}</p>
                </div>
            ";
            
            $mail->send();
            
        } catch (Exception $e) {
            error_log("Error al enviar correo: " . $e->getMessage());
        }
        
        $conn->commit();
        
        echo '
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Vacaciones Registradas</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 20px; background-color: #f5f5f5; }
                .container { max-width: 600px; margin: 30px auto; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
                h1 { color: #2ecc71; text-align: center; }
                .info { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .btn { display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>Vacaciones Registradas</h1>
                <div class="info">
                    <p><strong>Empleado:</strong> ' . htmlspecialchars($solicitud['nombre']) . '</p>
                    <p><strong>Días registrados:</strong> ' . $solicitud['dias_solicitados'] . '</p>
                    <p><strong>Días restantes:</strong> ' . $nuevosDiasRestantes . '</p>
                </div>
                <p>Se ha notificado al empleado.</p>
                <a href="javascript:window.close();" class="btn">Cerrar</a>
            </div>
        </body>
        </html>
        ';
        
    } catch (Exception $e) {
        $conn->rollback();
        throw $e;
    }
    
} catch (Exception $e) {
    echo '<h2>Error: ' . htmlspecialchars($e->getMessage()) . '</h2>';
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}