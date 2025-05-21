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

if (!in_array($action, ['approve', 'reject']) || $id <= 0) {
    die('<h2>Error: Parámetros inválidos</h2>');
}

// Procesar rechazo con comentario
if ($action === 'reject' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $comentarioRechazo = $_POST['comentario_rechazo'] ?? '';
    
    if (empty(trim($comentarioRechazo))) {
        die('<h2>Error: Debe proporcionar un motivo para el rechazo</h2>');
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
        
        $consulta = $conn->prepare("SELECT * FROM solicitudes_vacaciones WHERE id = ? AND status = 'pendiente'");
        $consulta->bind_param("i", $id);
        $consulta->execute();
        $resultado = $consulta->get_result();
        $solicitud = $resultado->fetch_assoc();
        
        if (!$solicitud) {
            throw new Exception("No se encontró la solicitud o ya fue procesada");
        }
        
        $conn->begin_transaction();
        
        // Actualizar estado y agregar comentario de rechazo
        $stmt = $conn->prepare("UPDATE solicitudes_vacaciones SET status = 'rechazado', comentarios_rechazo = ? WHERE id = ?");
        $stmt->bind_param("si", $comentarioRechazo, $id);
        $stmt->execute();
        
        // Enviar correo al empleado con el motivo del rechazo
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
            $mail->Subject = 'Solicitud de Vacaciones Rechazada';
            
            $mail->Body = "
                <h2>Solicitud Rechazada</h2>
                <p>Hola {$solicitud['nombre']},</p>
                <p>Tu solicitud de vacaciones ha sido rechazada por tu supervisor.</p>
                
                <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                    <p><strong>Motivo del rechazo:</strong></p>
                    <p>" . nl2br(htmlspecialchars($comentarioRechazo)) . "</p>
                </div>
                
                <p>Por favor, contacta a tu supervisor para más información.</p>
            ";
            
            $mail->send();
            
        } catch (Exception $e) {
            error_log("Error al enviar correo: " . $e->getMessage());
        }
        
        $conn->commit();
        
        // Mostrar confirmación
        echo '
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Solicitud Rechazada</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 20px; background-color: #f5f5f5; }
                .container { max-width: 600px; margin: 30px auto; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
                h1 { color: #e74c3c; text-align: center; }
                .info { background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0; }
                .btn { display: inline-block; padding: 10px 20px; background: #3498db; color: white; text-decoration: none; border-radius: 5px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>Solicitud Rechazada</h1>
                <div class="info">
                    <p><strong>ID:</strong> ' . $id . '</p>
                    <p><strong>Empleado:</strong> ' . htmlspecialchars($solicitud['nombre']) . '</p>
                    <p><strong>Motivo:</strong> ' . nl2br(htmlspecialchars($comentarioRechazo)) . '</p>
                </div>
                <p>Se ha notificado al empleado sobre el rechazo de su solicitud.</p>
                <a href="javascript:window.close();" class="btn">Cerrar</a>
            </div>
        </body>
        </html>
        ';
        
        exit();
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollback();
        }
        echo '<h2>Error: ' . htmlspecialchars($e->getMessage()) . '</h2>';
        exit();
    }
}

// Procesar aprobación o mostrar formulario de rechazo
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
    
    $consulta = $conn->prepare("SELECT * FROM solicitudes_vacaciones WHERE id = ? AND status = 'pendiente'");
    $consulta->bind_param("i", $id);
    $consulta->execute();
    $resultado = $consulta->get_result();
    $solicitud = $resultado->fetch_assoc();
    
    if (!$solicitud) {
        throw new Exception("No se encontró la solicitud o ya fue procesada");
    }
    
    if ($action === 'approve') {
        $conn->begin_transaction();
        
        try {
            $status = 'aprobado_supervisor';
            $stmt = $conn->prepare("UPDATE solicitudes_vacaciones SET status = ? WHERE id = ?");
            $stmt->bind_param("si", $status, $id);
            $stmt->execute();
            
            $emailNominas = ($solicitud['razon_social'] === 'IMPERQUIMIA' || $solicitud['razon_social'] === 'GRAVIQ' || $solicitud['razon_social'] === 'GOGAL' || $solicitud['razon_social'] === 'IQ Servicios')
                ? $_ENV['EMAIL_NOMINAS_FIRST'] 
                : $_ENV['EMAIL_NOMINAS_OTHER'];
            
            $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
            $host = $_SERVER['HTTP_HOST'];
            $baseUrl = $_ENV['APP_URL'] ?? "$protocol://$host/imperquimiaVacaciones";
            $registrarLink = "$baseUrl/backend/accion_nominas.php?action=register&id=$id";
            
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
                $mail->addAddress($emailNominas);
                $mail->Subject = 'Solicitud de Vacaciones Aprobada - ' . $solicitud['nombre'];
                
                $mail->Body = "
                    <h2>Solicitud de Vacaciones Aprobada</h2>
                    <p>El supervisor ha aprobado la siguiente solicitud:</p>
                    
                    <div style='background-color: #f8f9fa; padding: 15px; border-radius: 5px; margin: 15px 0;'>
                        <p><strong>Empleado:</strong> {$solicitud['nombre']} (#{$solicitud['numero_empleado']})</p>
                        <p><strong>Periodo:</strong> {$solicitud['fecha_inicio']} al {$solicitud['fecha_fin']}</p>
                        <p><strong>Días:</strong> {$solicitud['dias_solicitados']}</p>
                    </div>
                    
                    <p>Por favor, registra estas vacaciones:</p>
                    <div style='margin: 20px 0;'>
                        <a href='{$registrarLink}' style='background: #3498db; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Registrar Vacaciones</a>
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
                <title>Solicitud Aprobada</title>
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
                    <h1>Solicitud Aprobada</h1>
                    <div class="info">
                        <p><strong>ID:</strong> ' . $id . '</p>
                        <p><strong>Empleado:</strong> ' . htmlspecialchars($solicitud['nombre']) . '</p>
                        <p><strong>Días:</strong> ' . $solicitud['dias_solicitados'] . '</p>
                    </div>
                    <p>Se ha notificado al área de nóminas para su registro.</p>
                    <a href="javascript:window.close();" class="btn">Cerrar</a>
                </div>
            </body>
            </html>
            ';
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    } elseif ($action === 'reject') {
        // Mostrar formulario para comentario de rechazo
        echo '
        <!DOCTYPE html>
        <html lang="es">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Rechazar Solicitud</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; margin: 0; padding: 20px; background-color: #f5f5f5; }
                .container { max-width: 600px; margin: 30px auto; padding: 20px; background: white; border-radius: 8px; box-shadow: 0 0 10px rgba(0,0,0,0.1); }
                h1 { color: #e74c3c; text-align: center; }
                .form-group { margin-bottom: 15px; }
                label { display: block; margin-bottom: 5px; font-weight: bold; }
                textarea { width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px; min-height: 100px; }
                .btn { display: inline-block; padding: 10px 20px; color: white; text-decoration: none; border-radius: 5px; border: none; cursor: pointer; }
                .btn-rechazar { background: #e74c3c; }
                .btn-cancelar { background: #7f8c8d; margin-left: 10px; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>Rechazar Solicitud</h1>
                <form method="POST" action="?action=reject&id=' . $id . '">
                    <div class="form-group">
                        <label for="comentario_rechazo">Motivo del rechazo:</label>
                        <textarea id="comentario_rechazo" name="comentario_rechazo" required></textarea>
                    </div>
                    <button type="submit" class="btn btn-rechazar">Confirmar Rechazo</button>
                    <a href="javascript:window.close();" class="btn btn-cancelar">Cancelar</a>
                </form>
            </div>
        </body>
        </html>
        ';
    }
    
} catch (Exception $e) {
    echo '<h2>Error: ' . htmlspecialchars($e->getMessage()) . '</h2>';
} finally {
    if (isset($conn)) {
        $conn->close();
    }
}