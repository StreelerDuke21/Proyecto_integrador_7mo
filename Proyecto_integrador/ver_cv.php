<?php
session_start();

// Verificar que el usuario esté logueado
if (!isset($_SESSION['tipo_usuario'])) {
    header('Location: index.php');
    exit;
}

// Configuración de la base de datos
$host = 'localhost';
$username = 'root';
$password = '';
$database = 'proyecto_7';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$database;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Error de conexión: " . $e->getMessage());
}

// Obtener el ID del usuario del CV
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die("ID de usuario no válido");
}

$user_id = intval($_GET['id']);

// Verificar permisos: solo el propio usuario o una empresa puede ver el CV
if ($_SESSION['tipo_usuario'] === 'usuario' && $_SESSION['id'] != $user_id) {
    die("No tienes permisos para ver este CV");
}

// Obtener el CV de la base de datos
$stmt = $pdo->prepare("SELECT * FROM CV WHERE idusuario = ?");
$stmt->execute([$user_id]);
$cv = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cv) {
    die("CV no encontrado");
}

// Configurar headers para mostrar el PDF
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $cv['nombre'] . '"');
header('Content-Length: ' . strlen($cv['archivo']));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Mostrar el contenido del PDF
echo $cv['archivo'];
?>