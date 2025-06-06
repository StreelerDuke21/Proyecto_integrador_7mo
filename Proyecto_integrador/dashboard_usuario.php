<?php
session_start();

// 1. VERIFICAR SESIÓN
if (!isset($_SESSION['tipo_usuario']) || $_SESSION['tipo_usuario'] !== 'usuario') {
    header('Location: index.php');
    exit;
}

// 2. CONFIGURACIÓN BD
require 'conexion_bd.php'; // contiene $pdo
$user_id = $_SESSION['id'];

// 3. OBTENER DATOS
try {
    // 3.1 Usuario
    $stmt = $pdo->prepare("SELECT * FROM usuario WHERE idusuario = ?");
    $stmt->execute([$user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    // 3.2 CV
    $stmt = $pdo->prepare("SELECT * FROM cv WHERE idusuario = ?");
    $stmt->execute([$user_id]);
    $cv = $stmt->fetch(PDO::FETCH_ASSOC);

    // 3.3 Ofertas activas
    $stmt = $pdo->prepare("SELECT ot.*, e.nombre_empresa, e.logo
        FROM ofertas_trabajo ot
        JOIN empresa e ON ot.idempresa = e.idempresa
        WHERE ot.activa = 1
        ORDER BY ot.fecha_creacion DESC");
    $stmt->execute();
    $ofertas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3.4 Aplicaciones own
    $stmt = $pdo->prepare("SELECT idoferta, estado FROM aplicaciones WHERE idusuario = ?");
    $stmt->execute([$user_id]);
    $aplicaciones = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    // 3.5 Notificaciones
    $stmt = $pdo->prepare("SELECT * FROM notificaciones WHERE idusuario = ? ORDER BY fecha DESC");
    $stmt->execute([$user_id]);
    $notificaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 3.6 Chat: empresas con mensajes
    $stmt = $pdo->prepare("SELECT DISTINCT e.idempresa, e.nombre_empresa
        FROM mensajes m
        JOIN empresa e ON m.idempresa = e.idempresa
        WHERE m.idusuario = ?");
    $stmt->execute([$user_id]);
    $empresas_chat = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Error en consulta: " . $e->getMessage());
}

// 4. PROCESAR ACCIONES
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    try {
        switch ($action) {
            case 'actualizar_cv':
                if (!empty($_FILES['nuevo_cv']['tmp_name']) && $_FILES['nuevo_cv']['error'] === UPLOAD_ERR_OK) {
                    $file = $_FILES['nuevo_cv'];
                    $type = mime_content_type($file['tmp_name']);
                    if ($type === 'application/pdf' && $file['size'] <= 5 * 1024 * 1024) {
                        $content = file_get_contents($file['tmp_name']);
                        if ($cv) {
                            $stmt = $pdo->prepare("UPDATE cv SET nombre = ?, archivo = ?, tipo_archivo = ? WHERE idusuario = ?");
                            $stmt->execute([$file['name'], $content, 'pdf', $user_id]);
                        } else {
                            $stmt = $pdo->prepare("INSERT INTO cv (nombre, archivo, tipo_archivo, idusuario) VALUES (?, ?, ?, ?)");
                            $stmt->execute([$file['name'], $content, 'pdf', $user_id]);
                        }
                        $success = "CV subido/actualizado exitosamente.";
                        // recargar cv
                        $stmt = $pdo->prepare("SELECT * FROM cv WHERE idusuario = ?");
                        $stmt->execute([$user_id]);
                        $cv = $stmt->fetch(PDO::FETCH_ASSOC);
                    } else {
                        $error = "Archivo inválido. PDF y ≤ 5MB.";
                    }
                }
                break;

            case 'aplicar_oferta':
                $oferta_id = intval($_POST['oferta_id']);
                if (!isset($aplicaciones[$oferta_id])) {
                    $stmt = $pdo->prepare("INSERT INTO aplicaciones (idusuario, idoferta, estado) VALUES (?, ?, 'pendiente')");
                    $stmt->execute([$user_id, $oferta_id]);
                    $success = "Aplicación enviada exitosamente.";
                    // recargar
                    $aplicaciones[$oferta_id] = 'pendiente';
                } else {
                    $error = "Ya aplicaste a esta oferta.";
                }
                break;

            case 'retirar_aplicacion':
                $oferta_id = intval($_POST['oferta_id']);
                if (isset($aplicaciones[$oferta_id])) {
                    $stmt = $pdo->prepare("DELETE FROM aplicaciones WHERE idusuario = ? AND idoferta = ?");
                    $stmt->execute([$user_id, $oferta_id]);
                    unset($aplicaciones[$oferta_id]);
                    $success = "Aplicación retirada correctamente.";
                }
                break;

            case 'enviar_mensaje':
                $empresa_id = intval($_POST['empresa_id']);
                $mensaje = trim($_POST['mensaje']);
                if ($empresa_id > 0 && $mensaje !== '') {
                    $stmt = $pdo->prepare("INSERT INTO mensajes (idusuario, idempresa, contenido, emisor) VALUES (?, ?, ?, 'usuario')");
                    $stmt->execute([$user_id, $empresa_id, $mensaje]);
                    $success = "Mensaje enviado.";
                }
                break;

            default:
                break;
        }
    } catch (PDOException $e) {
        $error = "Error en acción: " . $e->getMessage();
    }
}

// A partir de aquí: HTML + JS (sin cambios mayores)
?>

<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Dashboard Usuario - <?php echo htmlspecialchars($usuario['nombre']); ?></title>
        <link rel="stylesheet" href="./css/dashboard_usuario.css">
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
    </head>
    <body>

        <!-- Botón de menú para móviles -->
        <button class="mobile-menu-btn" id="mobileMenuBtn">
            <i class="fas fa-bars"></i>
        </button>

        <!-- Overlay para cerrar el menú -->
        <div class="sidebar-overlay" id="sidebarOverlay"></div>

        <div class="dashboard-container">
            <!-- Sidebar -->
            <div class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <h2><i class="fas fa-user"></i> Usuario</h2>
                    <p><?php echo htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']); ?></p>
                </div>
                <nav class="sidebar-nav">
                    <a href="#perfil" class="nav-link active" onclick="showSection('perfil')">
                        <i class="fas fa-user-circle"></i> Mi Perfil
                    </a>
                    <a href="#cv" class="nav-link" onclick="showSection('cv')">
                        <i class="fas fa-file-pdf"></i> Mi CV
                    </a>
                    <a href="#ofertas" class="nav-link" onclick="showSection('ofertas')">
                        <i class="fas fa-briefcase"></i> Ofertas de Trabajo
                    </a>

                    <a href="#recomendaciones" class="nav-link" onclick="showSection('recomendaciones')">
                        <i class="fas fa-lightbulb"></i> Recomendaciones

                    </a>

                    <a href="#aplicaciones" class="nav-link" onclick="showSection('aplicaciones')">
                        <i class="fas fa-paper-plane"></i> Mis Aplicaciones
                    </a>

                    <a href="#chat" class="nav-link" onclick="showSection('chat')">
                        <i class="fas fa-comments"></i> Chat 
                        <?php if (count($notificaciones) > 0): ?>
                            <span class="notificacion-badge"><?= count($notificaciones) ?></span>
                        <?php endif; ?>
                    </a>

                    <a href="index.php" class="nav-link logout">
                        <i class="fas fa-sign-out-alt"></i> Cerrar Sesión
                    </a>
                </nav>
            </div>

            <!-- Main Content -->
            <div class="main-content">
                <!-- Header -->
                <div class="header">
                    <h1>Dashboard de Usuario</h1>
                    <div class="user-info">
                        <span>Bienvenido, <?php echo htmlspecialchars($usuario['nombre']); ?></span>
                    </div>
                </div>

                <!-- Alertas -->
                <?php if (isset($success)): ?>
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
                    </div>
                <?php endif; ?>

                <?php if (isset($error)): ?>
                    <div class="alert alert-error">
                        <i class="fas fa-exclamation-circle"></i> <?php echo htmlspecialchars($error); ?>
                    </div>
                <?php endif; ?>

                <!-- Sección Mi Perfil -->
                <div id="perfil-section" class="content-section active">
                    <div class="section-header">
                        <h2><i class="fas fa-user-circle"></i> Mi Perfil</h2>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <div class="profile-info">
                                <div class="info-group">
                                    <label>Nombre Completo:</label>
                                    <span><?php echo htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']); ?></span>
                                </div>
                                <div class="info-group">
                                    <label>Fecha de Nacimiento:</label>
                                    <span><?php echo htmlspecialchars($usuario['fecha_na']); ?></span>
                                </div>
                                <div class="info-group">
                                    <label>Correo Electrónico:</label>
                                    <span><?php echo htmlspecialchars($usuario['correro']); ?></span>
                                </div>
                                <div class="info-group">
                                    <label>Usuario:</label>
                                    <span><?php echo htmlspecialchars($usuario['usuario']); ?></span>
                                </div>
                                <div class="info-group">
                                    <label>Notificaciones:</label>
                                    <span class="badge <?php echo $usuario['notificacion'] === 'on' ? 'badge-success' : 'badge-secondary'; ?>">
                                        <?php echo $usuario['notificacion'] === 'on' ? 'Activadas' : 'Desactivadas'; ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sección Mi CV -->
                <div id="cv-section" class="content-section">
                    <div class="section-header">
                        <h2><i class="fas fa-file-pdf"></i> Mi Curriculum Vitae</h2>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <?php if ($cv): ?>
                                <div class="cv-info">
                                    <div class="cv-details">
                                        <h3><i class="fas fa-file-pdf"></i> <?php echo htmlspecialchars($cv['nombre']); ?></h3>
                                        <p><strong>Tipo:</strong> <?php echo strtoupper($cv['tipo_archivo']); ?></p>
                                        <p><strong>Tamaño:</strong> <?php echo number_format(strlen($cv['archivo']) / 1024, 2); ?> KB</p>
                                    </div>
                                    <div class="cv-actions">
                                        <a href="ver_cv.php?id=<?php echo $user_id; ?>" target="_blank" class="btn btn-primary">
                                            <i class="fas fa-eye"></i> Ver CV
                                        </a>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="no-cv">
                                    <i class="fas fa-file-excel" style="font-size: 48px; color: #ccc; margin-bottom: 20px;"></i>
                                    <p>No has subido tu CV aún</p>
                                </div>
                            <?php endif; ?>

                            <div class="cv-upload-section">
                                <h4><?php echo $cv ? 'Actualizar CV' : 'Subir CV'; ?></h4>
                                <form method="POST" enctype="multipart/form-data">
                                    <input type="hidden" name="action" value="actualizar_cv">
                                    <div class="file-upload">
                                        <input type="file" id="nuevo_cv" name="nuevo_cv" accept=".pdf" required onchange="updateFileName(this)">
                                        <label for="nuevo_cv" class="file-upload-label" id="cv-file-label">
                                            <i class="fas fa-upload"></i> Seleccionar archivo PDF (máx. 5MB)
                                        </label>
                                    </div>
                                    <button type="submit" class="btn btn-success" style="margin-top: 15px;">
                                        <i class="fas fa-save"></i> <?php echo $cv ? 'Actualizar CV' : 'Subir CV'; ?>
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sección Ofertas de Trabajo -->
                <div id="ofertas-section" class="content-section">
                    <div class="section-header">
                        <h2><i class="fas fa-briefcase"></i> Ofertas de Trabajo Disponibles</h2>
                    </div>


                    <div class="filtros-ofertas" style="margin-bottom: 20px;">
                        <div class="card">
                            <div class="card-body" style="padding: 15px;">
                                <div style="display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
                                    <div style="flex: 1; min-width: 200px;">
                                        <input type="text" id="buscar-ofertas" placeholder="Buscar por puesto, empresa o ubicación..." 
                                               style="width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px;">
                                    </div>
                                    <div>
                                        <select id="filtro-contrato" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px;">
                                            <option value="">Todos los contratos</option>
                                            <option value="tiempo completo">Tiempo Completo</option>
                                            <option value="medio tiempo">Medio Tiempo</option>
                                            <option value="temporal">Temporal</option>
                                            <option value="freelance">Freelance</option>
                                            <option value="practicas">Prácticas</option>
                                        </select>
                                    </div>
                                    <div>
                                        <select id="filtro-salario" style="padding: 8px 12px; border: 1px solid #ddd; border-radius: 5px;">
                                            <option value="">Cualquier salario</option>
                                            <option value="0-500000">$0 - $500,000</option>
                                            <option value="500000-1000000">$500,000 - $1,000,000</option>
                                            <option value="1000000-2000000">$1,000,000 - $2,000,000</option>
                                            <option value="2000000-99999999">Más de $2,000,000</option>
                                        </select>
                                    </div>
                                    <div>
                                        <button onclick="limpiarFiltros()" class="btn btn-secondary" style="padding: 8px 15px;">
                                            <i class="fas fa-times"></i> Limpiar
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>


                    <?php if (empty($ofertas)): ?>
                        <div class="card">
                            <div class="card-body text-center">
                                <i class="fas fa-briefcase" style="font-size: 48px; color: #ccc; margin-bottom: 20px;"></i>
                                <p>No hay ofertas de trabajo disponibles en este momento</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="ofertas-grid">
                            <?php foreach ($ofertas as $oferta): ?>
                                <div class="oferta-card">
                                    <div class="oferta-header">
                                        <div class="empresa-info">
                                            <?php if ($oferta['logo']): ?>
                                                <img src="data:image/jpeg;base64,<?php echo base64_encode($oferta['logo']); ?>" alt="Logo" class="empresa-logo">
                                            <?php else: ?>
                                                <div class="empresa-logo-placeholder">
                                                    <i class="fas fa-building"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <h3><?php echo htmlspecialchars($oferta['puesto']); ?></h3>
                                                <p class="empresa-nombre"><?php echo htmlspecialchars($oferta['nombre_empresa']); ?></p>
                                            </div>
                                        </div>

                                        <!-- Estado de aplicación -->
                                        <div class="aplicacion-estado">
                                            <?php if (isset($aplicaciones[$oferta['idoferta']])): ?>
                                                <?php $estado = $aplicaciones[$oferta['idoferta']]; ?>
                                                <span class="badge badge-<?php echo $estado === 'pendiente' ? 'warning' : ($estado === 'aceptada' ? 'success' : 'danger'); ?>">
                                                    <?php echo ucfirst($estado); ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                    </div>

                                    <div class="oferta-details">
                                        <div class="detail-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span><?php echo htmlspecialchars($oferta['lugar']); ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <i class="fas fa-file-contract"></i>
                                            <span><?php echo htmlspecialchars($oferta['contrato']); ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <i class="fas fa-clock"></i>
                                            <span><?php echo htmlspecialchars($oferta['hora_laborales']); ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <i class="fas fa-dollar-sign"></i>
                                            <span>$<?php echo number_format($oferta['salario'], 0, ',', '.'); ?></span>
                                        </div>
                                    </div>

                                    <div class="oferta-description">
                                        <h4>Descripción:</h4>
                                        <p><?php echo htmlspecialchars($oferta['descripcion']); ?></p>
                                    </div>

                                    <div class="oferta-requirements">
                                        <h4>Requerimientos:</h4>
                                        <p><?php echo htmlspecialchars($oferta['requerimiento']); ?></p>
                                    </div>

                                    <div style="margin-left:20px;" class="oferta-actions">
                                        <?php if (isset($aplicaciones[$oferta['idoferta']])): ?>
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="retirar_aplicacion">
                                                <input type="hidden" name="oferta_id" value="<?php echo $oferta['idoferta']; ?>">
                                                <button type="submit" class="btn btn-danger" onclick="return confirm('¿Estás seguro de retirar tu aplicación?')">
                                                    <i class="fas fa-times"></i> Retirar Aplicación
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <?php if ($cv): ?>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="aplicar_oferta">
                                                    <input type="hidden" name="oferta_id" value="<?php echo $oferta['idoferta']; ?>">
                                                    <button type="submit" class="btn btn-success">
                                                        <i class="fas fa-paper-plane"></i> Aplicar
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <button class="btn btn-secondary" disabled>
                                                    <i class="fas fa-exclamation-triangle"></i> Sube tu CV para aplicar
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>

                                    <div style="margin-left:20px; margin-top:10px; margin-bottom:20px;" class="oferta-meta">
                                        <small class="text-muted">
                                            <i class="fas fa-calendar"></i> 
                                            Publicada: <?php echo date('d/m/Y H:i', strtotime($oferta['fecha_creacion'])); ?>
                                        </small>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Sección Recomendación -->
                <div id="recomendaciones-section" class="content-section">
                    <div class="section-header">
                        <h2><i class="fas fa-lightbulb"></i> Recomendaciones</h2>
                    </div>
                    <div class="card">
                        <div class="card-body">
                            <?php if (!$cv): ?>
                                <div style="text-align: center; padding: 40px;">
                                    <i class="fas fa-file-upload" style="font-size: 48px; color: #ccc; margin-bottom: 20px;"></i>
                                    <h3>Sube tu CV para obtener recomendaciones</h3>
                                    <p>Para generar recomendaciones personalizadas con Inteligencia Artificial, necesitas subir tu curriculum vitae primero.</p>
                                    <button class="btn btn-primary" onclick="showSection('cv')">
                                        <i class="fas fa-upload"></i> Subir mi CV
                                    </button>
                                </div>
                            <?php elseif (empty($ofertas)): ?>
                                <div style="text-align: center; padding: 40px;">
                                    <i class="fas fa-briefcase" style="font-size: 48px; color: #ccc; margin-bottom: 20px;"></i>
                                    <h3>No hay ofertas disponibles</h3>
                                    <p>En este momento no hay ofertas de trabajo disponibles para generar recomendaciones.</p>
                                </div>
                            <?php else: ?>
                                <div style="text-align: center; padding: 40px;">
                                    <i class="fas fa-robot" style="font-size: 48px; color: #667eea; margin-bottom: 20px;"></i>
                                    <h3>Análisis Inteligente de tu CV</h3>
                                    <p>Obtén recomendaciones personalizadas basadas en tu curriculum vitae y las ofertas disponibles, generadas por Inteligencia Artificial.</p>

                                    <div style="display: flex; justify-content: center; gap: 15px; margin-top: 30px; flex-wrap: wrap;">
                                        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; min-width: 120px;">
                                            <i class="fas fa-file-pdf" style="color: #667eea; font-size: 1.5em;"></i>
                                            <div style="margin-top: 5px; font-size: 0.9em; color: #666;">CV Subido</div>
                                        </div>
                                        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; min-width: 120px;">
                                            <i class="fas fa-briefcase" style="color: #28a745; font-size: 1.5em;"></i>
                                            <div style="margin-top: 5px; font-size: 0.9em; color: #666;"><?php echo count($ofertas); ?> Ofertas</div>
                                        </div>
                                        <div style="background: #f8f9fa; padding: 15px; border-radius: 8px; min-width: 120px;">
                                            <i class="fas fa-robot" style="color: #dc3545; font-size: 1.5em;"></i>
                                            <div style="margin-top: 5px; font-size: 0.9em; color: #666;">IA Avanzada</div>
                                        </div>
                                    </div>

                                    <a href="recomendaciones_ia.php" class="btn btn-success" style="margin-top: 30px; padding: 12px 30px; font-size: 1.1em;">
                                        <i class="fas fa-magic"></i> Generar Recomendaciones con IA
                                    </a>

                                    <div style="margin-top: 20px; padding: 15px; background: #e3f2fd; border-radius: 5px; border-left: 4px solid #2196f3;">
                                        <small style="color: #1976d2;">
                                            <i class="fas fa-info-circle"></i> 
                                            El análisis incluye compatibilidad con ofertas, fortalezas del perfil, áreas de mejora y consejos personalizados.
                                        </small>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <!-- Sección Chat -->
                <div id="chat-section" class="content-section">
                    <div class="section-header">
                        <h2><i class="fas fa-comments"></i> Chat con Empresas</h2>
                    </div>
                    <div class="card">
                        <div class="card-body" style="display: flex; gap: 20px;">
                            <div class="usuarios-lista" style="flex: 1;">
                                <?php foreach ($empresas_chat as $empresa): ?>
                                    <div class="usuario-chat" onclick="cargarChat(<?= $empresa['idempresa'] ?>)">
                                        <?= htmlspecialchars($empresa['nombre_empresa']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div style="flex: 3;">
                                <div id="area-chat" class="chat-container"></div>
                                <form id="form-chat" method="POST" style="margin-top:15px">
                                    <input type="hidden" name="action" value="enviar_mensaje">
                                    <input type="hidden" id="empresa_id" name="empresa_id">
                                    <div style="display: flex; gap: 10px;">
                                        <input type="text" name="mensaje" placeholder="Escribe tu mensaje..." 
                                               style="flex: 1; padding: 8px;" required>
                                        <button type="submit" class="btn btn-primary">
                                            <i class="fas fa-paper-plane"></i> Enviar
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Nueva Sección: Mis Aplicaciones -->
                <div id="aplicaciones-section" class="content-section">
                    <div class="section-header">
                        <h2><i class="fas fa-paper-plane"></i> Mis Aplicaciones</h2>
                    </div>

                    <?php
                    // Obtener aplicaciones del usuario con detalles de la oferta
                    $stmt = $pdo->prepare("
                    SELECT a.*, ot.puesto, ot.salario, ot.lugar, e.nombre_empresa, e.logo
                    FROM aplicaciones a
                    JOIN ofertas_trabajo ot ON a.idoferta = ot.idoferta
                    JOIN empresa e ON ot.idempresa = e.idempresa
                    WHERE a.idusuario = ?
                    ORDER BY a.fecha_aplicacion DESC
                ");
                    $stmt->execute([$user_id]);
                    $mis_aplicaciones = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>

                    <?php if (empty($mis_aplicaciones)): ?>
                        <div class="card">
                            <div class="card-body text-center">
                                <i class="fas fa-paper-plane" style="font-size: 48px; color: #ccc; margin-bottom: 20px;"></i>
                                <p>No has aplicado a ninguna oferta de trabajo aún</p>
                                <button class="btn btn-primary" onclick="showSection('ofertas')">
                                    <i class="fas fa-briefcase"></i> Ver Ofertas Disponibles
                                </button>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="aplicaciones-grid">
                            <?php foreach ($mis_aplicaciones as $aplicacion): ?>
                                <div class="aplicacion-card">
                                    <div class="aplicacion-header">
                                        <div class="empresa-info">
                                            <?php if ($aplicacion['logo']): ?>
                                                <img src="data:image/jpeg;base64,<?php echo base64_encode($aplicacion['logo']); ?>" alt="Logo" class="empresa-logo">
                                            <?php else: ?>
                                                <div class="empresa-logo-placeholder">
                                                    <i class="fas fa-building"></i>
                                                </div>
                                            <?php endif; ?>
                                            <div>
                                                <h3><?php echo htmlspecialchars($aplicacion['puesto']); ?></h3>
                                                <p class="empresa-nombre"><?php echo htmlspecialchars($aplicacion['nombre_empresa']); ?></p>
                                            </div>
                                        </div>

                                        <span class="badge badge-<?php echo $aplicacion['estado'] === 'pendiente' ? 'warning' : ($aplicacion['estado'] === 'aceptada' ? 'success' : 'danger'); ?>">
                                            <?php echo ucfirst($aplicacion['estado']); ?>
                                        </span>
                                    </div>

                                    <div style="margin-left:30px; margin-top:10px;" class="aplicacion-details">
                                        <div class="detail-item">
                                            <i class="fas fa-map-marker-alt"></i>
                                            <span><?php echo htmlspecialchars($aplicacion['lugar']); ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <i class="fas fa-dollar-sign"></i>
                                            <span>$<?php echo number_format($aplicacion['salario'], 0, ',', '.'); ?></span>
                                        </div>
                                        <div class="detail-item">
                                            <i class="fas fa-calendar"></i>
                                            <span>Aplicado: <?php echo date('d/m/Y H:i', strtotime($aplicacion['fecha_aplicacion'])); ?></span>
                                        </div>
                                    </div>

                                    <?php if ($aplicacion['estado'] === 'pendiente'): ?>
                                        <div class="aplicacion-actions">
                                            <form method="POST" style="display: inline;">
                                                <input type="hidden" name="action" value="retirar_aplicacion">
                                                <input type="hidden" name="oferta_id" value="<?php echo $aplicacion['idoferta']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('¿Estás seguro de retirar tu aplicación?')">
                                                    <i class="fas fa-times"></i> Retirar
                                                </button>
                                            </form>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>


            </div>
        </div>

        <script>
            // Función para mostrar/ocultar el sidebar en móviles
            function toggleSidebar() {
                const sidebar = document.getElementById('sidebar');
                const overlay = document.getElementById('sidebarOverlay');
                sidebar.classList.toggle('active');
                overlay.classList.toggle('active');
            }

            // Event listeners para el menú móvil
            document.getElementById('mobileMenuBtn').addEventListener('click', toggleSidebar);
            document.getElementById('sidebarOverlay').addEventListener('click', toggleSidebar);

            // Cerrar el menú al seleccionar una opción (solo en móviles)
            document.querySelectorAll('.nav-link').forEach(link => {
                link.addEventListener('click', function () {
                    if (window.innerWidth <= 768) {
                        toggleSidebar();
                    }
                });
            });

            function showSection(section) {
                // Ocultar todas las secciones
                document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
                document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));

                // Mostrar la sección seleccionada
                document.getElementById(section + '-section').classList.add('active');
                document.querySelector(`[onclick="showSection('${section}')"]`).classList.add('active');
            }

            function updateFileName(input) {
                const label = document.getElementById('cv-file-label');
                if (input.files && input.files[0]) {
                    const fileName = input.files[0].name;
                    const fileSize = (input.files[0].size / 1024 / 1024).toFixed(2);
                    label.innerHTML = `<i class="fas fa-check"></i> ${fileName} (${fileSize} MB)`;
                    label.style.color = '#28a745';
                } else {
                    label.innerHTML = '<i class="fas fa-upload"></i> Seleccionar archivo PDF (máx. 5MB)';
                    label.style.color = '';
                }
            }

            // ======= NUEVO: Funciones para el Chat =======
            function cargarChat(empresa_id) {
                document.getElementById('empresa_id').value = empresa_id;
                fetch(`chat.php?empresa_id=${empresa_id}`)
                        .then(response => response.text())
                        .then(data => {
                            document.getElementById('area-chat').innerHTML = data;
                        });
            }

            // Recargar chat cada 5 segundos
            setInterval(() => {
                if (document.getElementById('chat-section').classList.contains('active')) {
                    const empresa_id = document.getElementById('empresa_id').value;
                    if (empresa_id)
                        cargarChat(empresa_id);
                }
            }, 5000);


            // ======= FILTROS DE OFERTAS =======
            function filtrarOfertas() {
                const busqueda = document.getElementById('buscar-ofertas').value.toLowerCase();
                const filtroContrato = document.getElementById('filtro-contrato').value.toLowerCase();
                const filtroSalario = document.getElementById('filtro-salario').value;

                const ofertas = document.querySelectorAll('.oferta-card');
                let ofertasVisibles = 0;

                ofertas.forEach(oferta => {
                    const puesto = oferta.querySelector('h3').textContent.toLowerCase();
                    const empresa = oferta.querySelector('.empresa-nombre').textContent.toLowerCase();
                    const ubicacion = oferta.querySelector('.detail-item i.fa-map-marker-alt').parentElement.textContent.toLowerCase();
                    const contrato = oferta.querySelector('.detail-item i.fa-file-contract').parentElement.textContent.toLowerCase();
                    const salarioText = oferta.querySelector('.detail-item i.fa-dollar-sign').parentElement.textContent;
                    const salario = parseInt(salarioText.replace(/[^0-9]/g, ''));

                    let mostrar = true;

                    // Filtro de búsqueda de texto
                    if (busqueda && !puesto.includes(busqueda) && !empresa.includes(busqueda) && !ubicacion.includes(busqueda)) {
                        mostrar = false;
                    }

                    // Filtro de tipo de contrato
                    if (filtroContrato && !contrato.includes(filtroContrato)) {
                        mostrar = false;
                    }

                    // Filtro de salario
                    if (filtroSalario) {
                        const [min, max] = filtroSalario.split('-').map(Number);
                        if (salario < min || (max && salario > max)) {
                            mostrar = false;
                        }
                    }

                    if (mostrar) {
                        oferta.style.display = 'block';
                        ofertasVisibles++;
                    } else {
                        oferta.style.display = 'none';
                    }
                });

                // Mostrar mensaje si no hay resultados
                let mensaje = document.getElementById('mensaje-sin-resultados');
                if (ofertasVisibles === 0) {
                    if (!mensaje) {
                        mensaje = document.createElement('div');
                        mensaje.id = 'mensaje-sin-resultados';
                        mensaje.className = 'card';
                        mensaje.innerHTML = `
                        <div class="card-body text-center">
                            <i class="fas fa-search" style="font-size: 48px; color: #ccc; margin-bottom: 20px;"></i>
                            <p>No se encontraron ofertas que coincidan con los filtros seleccionados</p>
                        </div>
                    `;
                        document.querySelector('.ofertas-grid').parentElement.appendChild(mensaje);
                    }
                    mensaje.style.display = 'block';
                } else if (mensaje) {
                    mensaje.style.display = 'none';
                }
            }

            function limpiarFiltros() {
                document.getElementById('buscar-ofertas').value = '';
                document.getElementById('filtro-contrato').value = '';
                document.getElementById('filtro-salario').value = '';
                filtrarOfertas();
            }

            // Event listeners para los filtros
            document.addEventListener('DOMContentLoaded', function () {
                const buscarInput = document.getElementById('buscar-ofertas');
                const filtroContrato = document.getElementById('filtro-contrato');
                const filtroSalario = document.getElementById('filtro-salario');

                if (buscarInput) {
                    buscarInput.addEventListener('input', filtrarOfertas);
                    filtroContrato.addEventListener('change', filtrarOfertas);
                    filtroSalario.addEventListener('change', filtrarOfertas);
                }
            });
        </script>

    </body>
</html>
