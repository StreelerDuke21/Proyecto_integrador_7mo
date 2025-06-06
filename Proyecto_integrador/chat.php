<?php
session_start();
require 'conexion_bd.php'; // Asume que tienes un archivo con la conexión

$user_id = $_SESSION['id'];
$tipo = $_SESSION['tipo_usuario'];

if ($tipo == 'usuario') {
    $empresa_id = intval($_GET['empresa_id']);
    $stmt = $pdo->prepare("SELECT * FROM mensajes 
        WHERE (idusuario = ? AND idempresa = ?)
        ORDER BY fecha ASC");
    $stmt->execute([$user_id, $empresa_id]);
} else {
    $usuario_id = intval($_GET['usuario_id']);
    $stmt = $pdo->prepare("SELECT * FROM mensajes 
        WHERE (idempresa = ? AND idusuario = ?)
        ORDER BY fecha ASC");
    $stmt->execute([$user_id, $usuario_id]);
}

$mensajes = $stmt->fetchAll();

foreach ($mensajes as $mensaje) {
    $clase = ($mensaje['emisor'] == $tipo) ? 'mensaje-propio' : 'mensaje-otro';
    echo "<div class='mensaje $clase'>
            <small>".date('d/m H:i', strtotime($mensaje['fecha']))."</small>
            <p>{$mensaje['contenido']}</p>
          </div>";
}
?>