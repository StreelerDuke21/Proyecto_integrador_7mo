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

// Verificar permisos: solo el propio usuario o una empresa puede descargar el CV
if ($_SESSION['tipo_usuario'] === 'usuario' && $_SESSION['id'] != $user_id) {
    die("No tienes permisos para descargar este CV");
}

// Obtener el CV y la información del usuario de la base de datos
$stmt = $pdo->prepare("
    SELECT cv.*, u.nombre, u.apellido 
    FROM CV cv 
    JOIN Usuario u ON cv.idusuario = u.idusuario 
    WHERE cv.idusuario = ?
");
$stmt->execute([$user_id]);
$cv = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cv) {
    die("CV no encontrado");
}

// Limpiar el nombre del archivo para la descarga
$nombre_completo = $cv['nombre'] . '_' . $cv['apellido'];
$nombre_archivo = preg_replace('/[^a-zA-Z0-9_-]/', '_', $nombre_completo);
$extension = strtolower($cv['tipo_archivo']) === 'pdf' ? '.pdf' : '.pdf';
$nombre_descarga = $nombre_archivo . '_CV' . $extension;

// Configurar headers para forzar la descarga
header('Content-Type: application/octet-stream');
header('Content-Disposition: attachment; filename="' . $nombre_descarga . '"');
header('Content-Length: ' . strlen($cv['archivo']));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');

// Limpiar cualquier salida previa
if (ob_get_level()) {
    ob_end_clean();
}

// Enviar el contenido del archivo
echo $cv['archivo'];
exit;
?>