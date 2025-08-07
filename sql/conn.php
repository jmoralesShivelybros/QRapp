<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "qrdb";
$port = 3306;

$conn = new mysqli($servername, $username, $password, $dbname, $port);

// Solo mostrar mensaje si se accede directamente
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    if ($conn->connect_error) {
        echo "❌ Error de conexión: " . $conn->connect_error;
    } else {
        echo "✅ Conexión exitosa a la base de datos '$dbname' como usuario '$username' en el puerto $port";
    }
}
?>