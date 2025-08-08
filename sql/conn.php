<?php
// Establecer codificación UTF-8
header('Content-Type: text/html; charset=utf-8');
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$servername = "sql101.infinityfree.com";
$username = "if0_39104991";
$password = "c4b4TbwWTGY";
$dbname = "if0_39104991_qrdb";
$port = 3306;

$conn = new mysqli($servername, $username, $password, $dbname, $port);
$conn->set_charset("utf8mb4");

// Solo mostrar mensaje si se accede directamente
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    if ($conn->connect_error) {
        echo json_encode([
            'success' => false,
            'message' => 'Error de conexión',
            'error' => $conn->connect_error
        ]);
    } else {
        echo json_encode([
            'success' => true,
            'message' => "Conexión exitosa a la base de datos '$dbname' como usuario '$username' en el puerto $port"
        ]);
    }
}
?>

