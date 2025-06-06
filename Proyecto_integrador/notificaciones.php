<?php
session_start();
require 'conexion_bd.php';

$user_id = $_SESSION['id'];
$tipo = $_SESSION['tipo_usuario'];

// Marcar como leídas
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $pdo->prepare("UPDATE notificaciones SET leida = 1 WHERE idusuario = ?")->execute([$user_id]);
    exit;
}

// Obtener notificaciones
$stmt = $pdo->prepare("SELECT * FROM notificaciones 
    WHERE idusuario = ? 
    ORDER BY fecha DESC 
    LIMIT 10");
$stmt->execute([$user_id]);
$notificaciones = $stmt->fetchAll();

foreach ($notificaciones as $notif) {
    $clase = $notif['leida'] ? 'leida' : 'nueva';
    echo "<div class='notificacion $clase'>
            <div class='fecha'>{$notif['fecha']}</div>
            <div class='mensaje'>{$notif['mensaje']}</div>
          </div>";
}
?>