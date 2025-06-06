<?php
// Configuración de la base de datos
$host = 'localhost';
$dbname = 'proyecto_7';
$username = 'root';
$password = '';

try {
    // Crear conexión PDO
    $pdo = new PDO(
        "mysql:host=$host;dbname=$dbname;charset=utf8",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
    
} catch (PDOException $e) {
    // Manejar errores de conexión
    die("Error de conexión: " . $e->getMessage());
}

// Función opcional para cerrar conexión (no necesaria en la mayoría de casos)
function cerrarConexion(&$pdo = null) {
    $pdo = null;
}
?>
