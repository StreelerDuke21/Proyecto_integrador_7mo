<?php
session_start();

// 1. VERIFICAR SESIÓN
if (!isset($_SESSION['tipo_usuario']) || $_SESSION['tipo_usuario'] !== 'empresa') {
    header('Location: index.php');
    exit;
}

// 2. CONEXIÓN A BD
require 'conexion_bd.php';
$empresa_id = $_SESSION['id'];

// 3. OBTENER DATOS PRINCIPALES
// 3.1 Información de la empresa
$stmt_empresa = $pdo->prepare("SELECT * FROM empresa WHERE idempresa = ?");
$stmt_empresa->execute([$empresa_id]);
$empresa = $stmt_empresa->fetch(PDO::FETCH_ASSOC);

// 3.2 Ofertas de trabajo activas
$stmt_ofertas = $pdo->prepare("SELECT * FROM ofertas_trabajo 
    WHERE idempresa = ? AND activa = TRUE 
    ORDER BY fecha_creacion DESC");
$stmt_ofertas->execute([$empresa_id]);
$ofertas = $stmt_ofertas->fetchAll();

// 3.3 Aplicaciones recibidas
$stmt_aplicaciones = $pdo->prepare("
    SELECT a.*, u.nombre, u.apellido, u.correro, 
           ot.puesto, cv.nombre as cv_nombre
    FROM aplicaciones a
    JOIN usuario u ON a.idusuario = u.idusuario
    JOIN ofertas_trabajo ot ON a.idoferta = ot.idoferta
    LEFT JOIN cv cv ON u.idusuario = cv.idusuario
    WHERE ot.idempresa = ?
    ORDER BY a.fecha_aplicacion DESC
");
$stmt_aplicaciones->execute([$empresa_id]);
$aplicaciones = $stmt_aplicaciones->fetchAll();

// 3.4 Notificaciones
$stmt_notificaciones = $pdo->prepare("SELECT * FROM notificaciones 
    WHERE idusuario = ? 
    ORDER BY fecha DESC");
$stmt_notificaciones->execute([$empresa_id]);
$notificaciones = $stmt_notificaciones->fetchAll();

// 3.5 Usuarios para chat
$stmt_chat = $pdo->prepare("
SELECT DISTINCT u.idusuario, u.nombre, u.apellido
FROM aplicaciones a
JOIN usuario u ON a.idusuario = u.idusuario
WHERE a.idoferta IN (
    SELECT idoferta FROM ofertas_trabajo WHERE idempresa = ?
)
");
$stmt_chat->execute([$empresa_id]);
$usuarios_chat = $stmt_chat->fetchAll();

// 4. PROCESAR FORMULARIOS
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            // 4.1 Crear oferta
            case 'crear_oferta':
                $campos = ['puesto', 'lugar', 'requerimiento', 'contrato', 'hora_laborales', 'salario', 'descripcion'];
                $datos = [];
                foreach ($campos as $campo) {
                    $datos[$campo] = trim($_POST[$campo]);
                }
                try {
                    $stmt = $pdo->prepare("INSERT INTO ofertas_trabajo 
                        (idempresa, puesto, lugar, requerimiento, contrato, 
                        hora_laborales, salario, descripcion) 
                        VALUES (?,?,?,?,?,?,?,?)");
                    $stmt->execute([
                        $empresa_id,
                        $datos['puesto'],
                        $datos['lugar'],
                        $datos['requerimiento'],
                        $datos['contrato'],
                        $datos['hora_laborales'],
                        $datos['salario'],
                        $datos['descripcion']
                    ]);
                    $success = "✅ Oferta creada exitosamente";
                    // Actualizar lista
                    $stmt_ofertas->execute([$empresa_id]);
                    $ofertas = $stmt_ofertas->fetchAll();
                } catch (PDOException $e) {
                    $error = "❌ Error: " . $e->getMessage();
                }
                break;

            // 4.2 Eliminar oferta
            case 'eliminar_oferta':
                $oferta_id = intval($_POST['oferta_id']);
                try {
                    $stmt = $pdo->prepare("UPDATE ofertas_trabajo 
                        SET activa = FALSE 
                        WHERE idoferta = ? AND idempresa = ?");
                    $stmt->execute([$oferta_id, $empresa_id]);
                    $success = "🗑️ Oferta eliminada";
                    $stmt_ofertas->execute([$empresa_id]);
                    $ofertas = $stmt_ofertas->fetchAll();
                } catch (PDOException $e) {
                    $error = "❌ Error: " . $e->getMessage();
                }
                break;

            // 4.3 Cambiar estado aplicación
            case 'cambiar_estado':
                $aplicacion_id = intval($_POST['aplicacion_id']);
                $nuevo_estado = $_POST['estado'];
                if (in_array($nuevo_estado, ['pendiente', 'revisando', 'aceptada', 'rechazada'])) {
                    try {
                        $stmt = $pdo->prepare("UPDATE aplicaciones 
                            SET estado = ? 
                            WHERE idaplicacion = ?");
                        $stmt->execute([$nuevo_estado, $aplicacion_id]);
                        $success = "🔄 Estado actualizado a: " . ucfirst($nuevo_estado);
                        $stmt_aplicaciones->execute([$empresa_id]);
                        $aplicaciones = $stmt_aplicaciones->fetchAll();
                    } catch (PDOException $e) {
                        $error = "❌ Error: " . $e->getMessage();
                    }
                }
                break;

            // 4.4 Enviar mensaje
            case 'enviar_mensaje':
                if (isset($_POST['usuario_id'], $_POST['mensaje'])) {
                    $usuario_id = intval($_POST['usuario_id']);
                    $mensaje = trim($_POST['mensaje']);

                    // 1) Verifico que haya seleccionado un usuario
                    if ($usuario_id <= 0) {
                        $error = "❌ Debes seleccionar un candidato antes de enviar un mensaje.";
                        break;
                    }

                    // 2) Verifico que el usuario exista
                    $stmt_check = $pdo->prepare("SELECT COUNT(*) FROM usuario WHERE idusuario = ?");
                    $stmt_check->execute([$usuario_id]);
                    if ($stmt_check->fetchColumn() == 0) {
                        $error = "❌ El candidato seleccionado no existe.";
                        break;
                    }

                    if (!empty($mensaje)) {
                        try {
                            $stmt = $pdo->prepare("INSERT INTO mensajes 
                                    (idempresa, idusuario, contenido, emisor) 
                                    VALUES (?, ?, ?, 'empresa')");
                            $stmt->execute([$empresa_id, $usuario_id, $mensaje]);
                            $success = "✅ Mensaje enviado.";
                        } catch (PDOException $e) {
                            $error = "❌ Error al enviar: " . $e->getMessage();
                        }
                    }
                }
                break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Dashboard Empresa - <?php echo htmlspecialchars($empresa['nombre_empresa']); ?></title>
        <link rel="stylesheet" href="./css/dashboard_empresa.css">
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
            <!-- Sidebar actualizado con ID correcto -->
            <div class="sidebar" id="sidebar">
                <div class="sidebar-header">
                    <h2><i class="fas fa-building"></i> Empresa</h2>
                    <p><?php echo htmlspecialchars($empresa['nombre_empresa']); ?></p>
                </div>
                <nav class="sidebar-nav">
                    <a href="#perfil" class="nav-link active" onclick="showSection('perfil')">
                        <i class="fas fa-building"></i> Mi Empresa
                    </a>
                    <a href="#ofertas" class="nav-link" onclick="showSection('ofertas')">
                        <i class="fas fa-briefcase"></i> Mis Ofertas
                    </a>
                    <a href="#crear-oferta" class="nav-link" onclick="showSection('crear-oferta')">
                        <i class="fas fa-plus"></i> Crear Oferta
                    </a>
                    <a href="#candidatos" class="nav-link" onclick="showSection('candidatos')">
                        <i class="fas fa-users"></i> Ver Candidatos
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
                    <h1>Dashboard de Empresa</h1>
                    <div class="user-info">
                        <span>Bienvenido, <?php echo htmlspecialchars($empresa['nombre_empresa']); ?></span>
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



                <!-- Sección Ver Candidatos -->
                <div id="candidatos-section" class="content-section">
                    <div class="section-header">
                        <h2><i class="fas fa-users"></i> Candidatos Postulados</h2>
                    </div>

                    <?php if (empty($aplicaciones)): ?>
                        <div class="card">
                            <div class="card-body text-center">
                                <i class="fas fa-users" style="font-size: 48px; color: #ccc; margin-bottom: 20px;"></i>
                                <p>No hay candidatos postulados a tus ofertas</p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="candidatos-grid">
                            <?php foreach ($aplicaciones as $app): ?>
                                <div class="candidato-card">
                                    <div class="candidato-header">
                                        <div class="candidato-info">
                                            <div class="candidato-avatar">
                                                <i class="fas fa-user"></i>
                                            </div>
                                            <div>
                                                <h3><?php echo htmlspecialchars($app['nombre'] . ' ' . $app['apellido']); ?></h3>
                                                <p class="candidato-email"><?php echo htmlspecialchars($app['correro']); ?></p>
                                                <small><i class="fas fa-briefcase"></i>
                                                    Postulado a: <?php echo htmlspecialchars($app['puesto']); ?></small><br>
                                                <small><i class="fas fa-calendar-alt"></i>
                                                    Fecha: <?php echo date('d/m/Y H:i', strtotime($app['fecha_aplicacion'])); ?></small>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="candidato-details">
                                        <div class="detail-item">
                                            <i class="fas fa-user-tag"></i>
                                            <span>Estado: 
                                                <span class="estado-badge estado-<?php echo $app['estado']; ?>">
                                                    <?php echo ucfirst($app['estado']); ?>
                                                </span>
                                            </span>
                                        </div>
                                    </div>

                                    <!-- Nueva sección para cambiar estado -->
                                    <div class="candidato-actions">
                                        <h4><i class="fas fa-cogs"></i> Cambiar Estado</h4>
                                        <form method="POST" class="estado-form">
                                            <input type="hidden" name="action" value="cambiar_estado">
                                            <input type="hidden" name="aplicacion_id" value="<?php echo $app['idaplicacion']; ?>">
                                            <div class="estado-selector">
                                                <select name="estado" class="form-select" onchange="this.form.submit()">
                                                    <option value="">-- Cambiar Estado --</option>
                                                    <option value="pendiente" <?php echo $app['estado'] === 'pendiente' ? 'selected' : ''; ?>>
                                                        🕐 Pendiente
                                                    </option>
                                                    <option value="revisando" <?php echo $app['estado'] === 'revisando' ? 'selected' : ''; ?>>
                                                        👀 Revisando
                                                    </option>
                                                    <option value="aceptada" <?php echo $app['estado'] === 'aceptada' ? 'selected' : ''; ?>>
                                                        ✅ Aceptada
                                                    </option>
                                                    <option value="rechazada" <?php echo $app['estado'] === 'rechazada' ? 'selected' : ''; ?>>
                                                        ❌ Rechazada
                                                    </option>
                                                </select>
                                            </div>
                                        </form>
                                    </div>

                                    <div class="candidato-cv">
                                        <?php if ($app['cv_nombre']): ?>
                                            <div class="cv-available">
                                                <h4><i class="fas fa-file-pdf"></i> CV Disponible</h4>
                                                <p><strong>Archivo:</strong> <?php echo htmlspecialchars($app['cv_nombre']); ?></p>
                                                <div class="cv-actions">
                                                    <a href="ver_cv.php?id=<?php echo $app['idusuario']; ?>" target="_blank" class="btn btn-primary btn-sm">
                                                        <i class="fas fa-eye"></i> Ver CV
                                                    </a>
                                                    <a href="descargar_cv.php?id=<?php echo $app['idusuario']; ?>" class="btn btn-success btn-sm">
                                                        <i class="fas fa-download"></i> Descargar
                                                    </a>
                                                </div>
                                            </div>
                                        <?php else: ?>
                                            <div class="cv-not-available">
                                                <i class="fas fa-file-excel" style="color: #ccc; margin-bottom: 10px;"></i>
                                                <p>Este candidato no ha subido su CV</p>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- /Fin Sección Ver Candidatos -->

                <!-- Sección Mi Empresa -->
                <div id="perfil-section" class="content-section active">
                    <div class="section-header">
                        <h2><i class="fas fa-building"></i> Información de la Empresa</h2>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <div class="profile-info">
                                <div class="info-group">
                                    <label>Nombre de la Empresa:</label>
                                    <span><?php echo htmlspecialchars($empresa['nombre_empresa']); ?></span>
                                </div>
                                <div class="info-group">
                                    <label>Correo Electrónico:</label>
                                    <span><?php echo htmlspecialchars($empresa['correro']); ?></span>
                                </div>
                                <div class="info-group">
                                    <label>Usuario:</label>
                                    <span><?php echo htmlspecialchars($empresa['usuario']); ?></span>
                                </div>
                                <div class="info-group">
                                    <label>Notificaciones:</label>
                                    <span class="badge <?php echo $empresa['notificacion'] === 'on' ? 'badge-success' : 'badge-secondary'; ?>">
                                        <?php echo $empresa['notificacion'] === 'on' ? 'Activadas' : 'Desactivadas'; ?>
                                    </span>
                                </div>
                                <div class="info-group">
                                    <label>Logo:</label>
                                    <?php if ($empresa['logo']): ?>
                                        <img src="data:image/jpeg;base64,<?php echo base64_encode($empresa['logo']); ?>" alt="Logo" style="max-width: 150px; max-height: 150px;">
                                    <?php else: ?>
                                        <span class="text-muted">No logo subido</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sección Mis Ofertas Mejorada -->
                <div id="ofertas-section" class="content-section">
                    <div class="section-header">
                        <h2><i class="fas fa-briefcase"></i> Mis Ofertas de Trabajo</h2>
                        <button class="btn btn-success" onclick="showSection('crear-oferta')">
                            <i class="fas fa-plus"></i> Nueva Oferta
                        </button>
                    </div>

                    <?php if (empty($ofertas)): ?>
                        <div class="empty-state">
                            <i class="fas fa-briefcase-open" aria-hidden="true"></i>
                            <p>No has creado ofertas aún</p>
                            <button class="btn btn-primary" onclick="showSection('crear-oferta')">
                                <i class="fas fa-plus"></i> Crear Oferta
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="ofertas-grid">
                            <?php foreach ($ofertas as $oferta): ?>
                                <div class="oferta-card">
                                    <header class="oferta-card-header">
                                        <h3><?php echo htmlspecialchars($oferta['puesto']); ?></h3>
                                        <form method="POST" onsubmit="return confirm('¿Eliminar oferta?');">
                                            <input type="hidden" name="action" value="eliminar_oferta">
                                            <input type="hidden" name="oferta_id" value="<?php echo $oferta['idoferta']; ?>">
                                            <button type="submit" class="btn-icon" title="Eliminar">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
                                        </form>
                                    </header>

                                    <ul class="oferta-meta-list">
                                        <li><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($oferta['lugar']); ?></li>
                                        <li><i class="fas fa-file-contract"></i> <?php echo htmlspecialchars($oferta['contrato']); ?></li>
                                        <li><i class="fas fa-clock"></i> <?php echo htmlspecialchars($oferta['hora_laborales']); ?></li>
                                        <li><i class="fas fa-dollar-sign"></i> $<?php echo number_format($oferta['salario'], 0, ',', '.'); ?></li>
                                    </ul>

                                    <section class="oferta-description">
                                        <h4>Descripción</h4>
                                        <p><?php echo htmlspecialchars($oferta['descripcion']); ?></p>
                                    </section>

                                    <section class="oferta-requirements">
                                        <h4>Requerimientos</h4>
                                        <p><?php echo htmlspecialchars($oferta['requerimiento']); ?></p>
                                    </section>

                                    <footer class="oferta-card-footer">
                                        <small>
                                            <i class="fas fa-calendar-alt"></i>
                                            Creada: <?php echo date('d/m/Y H:i', strtotime($oferta['fecha_creacion'])); ?>
                                        </small>
                                    </footer>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Sección Crear Oferta -->
                <div id="crear-oferta-section" class="content-section">
                    <div class="section-header">
                        <h2><i class="fas fa-plus"></i> Crear Nueva Oferta de Trabajo</h2>
                    </div>

                    <div class="card">
                        <div class="card-body">
                            <form method="POST">
                                <input type="hidden" name="action" value="crear_oferta">

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="puesto">Puesto de Trabajo:</label>
                                        <input type="text" id="puesto" name="puesto" required>
                                    </div>

                                    <div class="form-group">
                                        <label for="lugar">Ubicación (Remoto/Fisico):</label>
                                        <input type="text" id="lugar" name="lugar" required>
                                    </div>
                                </div>

                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="contrato">Tipo de Contrato:</label>
                                        <select id="contrato" name="contrato" required>
                                            <option value="">Seleccionar...</option>
                                            <option value="Tiempo completo">Tiempo completo</option>
                                            <option value="Medio tiempo">Medio tiempo</option>
                                            <option value="Por contrato">Por contrato</option>
                                            <option value="Temporal">Temporal</option>
                                            <option value="Pasantía">Pasantía</option>
                                        </select>
                                    </div>

                                    <div class="form-group">
                                        <label for="hora_laborales">Horario Laboral:</label>
                                        <input type="text" id="hora_laborales" name="hora_laborales" placeholder="Ej: 8:00 AM - 5:00 PM" required>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="salario">Salario (USD):</label>
                                    <input type="number" id="salario" name="salario" step="0.01" min="0" required>
                                </div>

                                <div class="form-group">
                                    <label for="requerimiento">Requerimientos:</label>
                                    <textarea id="requerimiento" name="requerimiento" rows="3" placeholder="Ej: Título universitario, 2 años de experiencia..." required></textarea>
                                </div>

                                <div class="form-group">
                                    <label for="descripcion">Descripción del Trabajo:</label>
                                    <textarea id="descripcion" name="descripcion" rows="5" placeholder="Describe las responsabilidades y tareas del puesto..." required></textarea>
                                </div>

                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-plus"></i> Crear Oferta
                                </button>
                            </form>
                        </div>
                    </div>
                </div>

                <!-- Sección Chat -->
                <div id="chat-section" class="content-section">
                    <div class="section-header">
                        <h2><i class="fas fa-comments"></i> Chat con Candidatos</h2>
                    </div>
                    <div class="card">
                        <div class="card-body" style="display: flex; gap: 20px;">
                            <div class="usuarios-lista" style="flex: 1;">
                                <?php foreach ($usuarios_chat as $usuario): ?>
                                    <div class="usuario-chat" onclick="cargarChat(<?= $usuario['idusuario'] ?>)">
                                        <?= htmlspecialchars($usuario['nombre'] . ' ' . $usuario['apellido']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <div style="flex: 3;">
                                <div id="area-chat" class="chat-container"></div>
                                <form id="form-chat" method="POST" style="margin-top:15px">
                                    <input type="hidden" name="action" value="enviar_mensaje">
                                    <input type="hidden" id="usuario_id" name="usuario_id">
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

            // ======= Funciones actualizadas para el Chat =======
            function cargarChat(usuario_id) {
                document.getElementById('usuario_id').value = usuario_id;
                fetch(`chat.php?usuario_id=${usuario_id}&tipo=empresa`)
                        .then(response => response.text())
                        .then(data => {
                            document.getElementById('area-chat').innerHTML = data;
                            // Auto-scroll al final
                            const container = document.getElementById('area-chat');
                            container.scrollTop = container.scrollHeight;
                        });
            }

            function showSection(section) {
                // Ocultar todas las secciones
                document.querySelectorAll('.content-section').forEach(s => s.classList.remove('active'));
                document.querySelectorAll('.nav-link').forEach(l => l.classList.remove('active'));

                // Mostrar la sección seleccionada
                document.getElementById(section + '-section').classList.add('active');
                document.querySelector(`[onclick="showSection('${section}')"]`).classList.add('active');
            }

            // Recargar chat cada 5 segundos
            setInterval(() => {
                if (document.getElementById('chat-section').classList.contains('active')) {
                    const usuario_id = document.getElementById('usuario_id').value;
                    if (usuario_id)
                        cargarChat(usuario_id);
                }
            }, 5000);
        </script>
    </body>
</html>


