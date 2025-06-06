<?php
session_start();

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

// Procesar formularios
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'login_empresa':
                $usuario = trim($_POST['usuario']);
                $contrasena = $_POST['contrasena'];
                
                $stmt = $pdo->prepare("SELECT * FROM empresa WHERE usuario = ?");
                $stmt->execute([$usuario]);
                $empresa = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($empresa && password_verify($contrasena, $empresa['contrasena'])) {
                    $_SESSION['tipo_usuario'] = 'empresa';
                    $_SESSION['id'] = $empresa['idempresa'];
                    $_SESSION['nombre'] = $empresa['nombre_empresa'];
                    header('Location: dashboard_empresa.php');
                    exit;
                } else {
                    $error_empresa = "Usuario o contraseña incorrectos";
                }
                break;
                
            case 'login_usuario':
                $usuario = trim($_POST['usuario']);
                $contrasena = $_POST['contrasena'];
                
                $stmt = $pdo->prepare("SELECT * FROM Usuario WHERE usuario = ?");
                $stmt->execute([$usuario]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && password_verify($contrasena, $user['contrasena'])) {
                    $_SESSION['tipo_usuario'] = 'usuario';
                    $_SESSION['id'] = $user['idusuario'];
                    $_SESSION['nombre'] = $user['nombre'] . ' ' . $user['apellido'];
                    header('Location: dashboard_usuario.php');
                    exit;
                } else {
                    $error_usuario = "Usuario o contraseña incorrectos";
                }
                break;
                
            case 'registro_empresa':
                $nombre_empresa = trim($_POST['nombre_empresa']);
                $correo = trim($_POST['correo']);
                $usuario = trim($_POST['usuario']);
                $contrasena = password_hash($_POST['contrasena'], PASSWORD_DEFAULT);
                
                try {
                    $stmt = $pdo->prepare("INSERT INTO empresa (nombre_empresa, correro, usuario, contrasena) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$nombre_empresa, $correo, $usuario, $contrasena]);
                    $success_empresa = "Empresa registrada exitosamente";
                } catch(PDOException $e) {
                    if ($e->getCode() == 23000) {
                        $error_empresa = "El usuario o correo ya existe";
                    } else {
                        $error_empresa = "Error al registrar la empresa";
                    }
                }
                break;
                
            case 'registro_usuario':
                $nombre = trim($_POST['nombre']);
                $apellido = trim($_POST['apellido']);
                $fecha_na = $_POST['fecha_na'];
                $correo = trim($_POST['correo']);
                $usuario = trim($_POST['usuario']);
                $contrasena = password_hash($_POST['contrasena'], PASSWORD_DEFAULT);
                
                // Validar que se subió un archivo PDF
                if (!isset($_FILES['cv']) || $_FILES['cv']['error'] !== UPLOAD_ERR_OK) {
                    $error_usuario = "Debe subir un archivo CV en formato PDF";
                    break;
                }
                
                $cv_file = $_FILES['cv'];
                $file_type = mime_content_type($cv_file['tmp_name']);
                
                if ($file_type !== 'application/pdf') {
                    $error_usuario = "El CV debe ser un archivo PDF";
                    break;
                }
                
                if ($cv_file['size'] > 5 * 1024 * 1024) { // 5MB máximo
                    $error_usuario = "El archivo CV no debe exceder 5MB";
                    break;
                }
                
                try {
                    $pdo->beginTransaction();
                    
                    // Insertar usuario
                    $stmt = $pdo->prepare("INSERT INTO Usuario (nombre, apellido, fecha_na, correro, usuario, contrasena) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$nombre, $apellido, $fecha_na, $correo, $usuario, $contrasena]);
                    $user_id = $pdo->lastInsertId();
                    
                    // Guardar CV
                    $cv_content = file_get_contents($cv_file['tmp_name']);
                    $stmt = $pdo->prepare("INSERT INTO CV (idcv, nombre, archivo, tipo_archivo, idusuario) VALUES (?, ?, ?, ?, ?)");
                    $stmt->execute([$user_id, $cv_file['name'], $cv_content, 'pdf', $user_id]);
                    
                    $pdo->commit();
                    $success_usuario = "Usuario registrado exitosamente con CV";
                } catch(PDOException $e) {
                    $pdo->rollBack();
                    if ($e->getCode() == 23000) {
                        $error_usuario = "El usuario o correo ya existe";
                    } else {
                        $error_usuario = "Error al registrar el usuario";
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
    <title>Sistema de Empleos - Login</title>
    <link rel="stylesheet" href="./css/login_stilos.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="container">
        <div class="tabs">
            <div class="tab active" onclick="showTab('empresa')">🏢 Empresas</div>
            <div class="tab" onclick="showTab('usuario')">👤 Usuarios</div>
        </div>

        <div class="content">
            <!-- Sección Empresas -->
            <div id="empresa-section" class="form-section active">
                <div id="empresa-login" class="form-container">
                    <h2 style="text-align: center; margin-bottom: 30px; color: #495057;">Iniciar Sesión - Empresa</h2>
                    
                    <?php if (isset($error_empresa)): ?>
                        <div class="alert alert-error"><?php echo htmlspecialchars($error_empresa); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($success_empresa)): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success_empresa); ?></div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="login_empresa">
                        
                        <div class="form-group">
                            <label for="usuario_empresa">Usuario:</label>
                            <input type="text" id="usuario_empresa" name="usuario" required>
                        </div>

                        <div class="form-group">
                            <label for="contrasena_empresa">Contraseña:</label>
                            <input type="password" id="contrasena_empresa" name="contrasena" required>
                        </div>

                        <button type="submit" class="btn">Iniciar Sesión</button>
                    </form>

                    <div class="form-toggle">
                        <p>¿No tienes cuenta? <a href="#" onclick="toggleEmpresaForm()">Regístrate aquí</a></p>
                    </div>
                </div>

                <div id="empresa-registro" class="form-container" style="display: none;">
                    <h2 style="text-align: center; margin-bottom: 30px; color: #495057;">Registro - Empresa</h2>

                    <form method="POST">
                        <input type="hidden" name="action" value="registro_empresa">
                        
                        <div class="form-group">
                            <label for="nombre_empresa">Nombre de la Empresa:</label>
                            <input type="text" id="nombre_empresa" name="nombre_empresa" required>
                        </div>

                        <div class="form-group">
                            <label for="correo_empresa">Correo Electrónico:</label>
                            <input type="email" id="correo_empresa" name="correo" required>
                        </div>

                        <div class="form-group">
                            <label for="usuario_empresa_reg">Usuario:</label>
                            <input type="text" id="usuario_empresa_reg" name="usuario" required>
                        </div>

                        <div class="form-group">
                            <label for="contrasena_empresa_reg">Contraseña:</label>
                            <input type="password" id="contrasena_empresa_reg" name="contrasena" required>
                        </div>

                        <button type="submit" class="btn">Registrar Empresa</button>
                        <button type="button" class="btn btn-secondary" onclick="toggleEmpresaForm()">Volver al Login</button>
                    </form>
                </div>
            </div>

            <!-- Sección Usuarios -->
            <div id="usuario-section" class="form-section">
                <div id="usuario-login" class="form-container">
                    <h2 style="text-align: center; margin-bottom: 30px; color: #495057;">Iniciar Sesión - Usuario</h2>
                    
                    <?php if (isset($error_usuario)): ?>
                        <div class="alert alert-error"><?php echo htmlspecialchars($error_usuario); ?></div>
                    <?php endif; ?>
                    
                    <?php if (isset($success_usuario)): ?>
                        <div class="alert alert-success"><?php echo htmlspecialchars($success_usuario); ?></div>
                    <?php endif; ?>

                    <form method="POST">
                        <input type="hidden" name="action" value="login_usuario">
                        
                        <div class="form-group">
                            <label for="usuario_user">Usuario:</label>
                            <input type="text" id="usuario_user" name="usuario" required>
                        </div>

                        <div class="form-group">
                            <label for="contrasena_user">Contraseña:</label>
                            <input type="password" id="contrasena_user" name="contrasena" required>
                        </div>

                        <button type="submit" class="btn">Iniciar Sesión</button>
                    </form>

                    <div class="form-toggle">
                        <p>¿No tienes cuenta? <a href="#" onclick="toggleUsuarioForm()">Regístrate aquí</a></p>
                    </div>
                </div>

                <div id="usuario-registro" class="form-container" style="display: none;">
                    <h2 style="text-align: center; margin-bottom: 30px; color: #495057;">Registro - Usuario</h2>

                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="registro_usuario">
                        
                        <div class="two-column">
                            <div class="form-group">
                                <label for="nombre_user">Nombre:</label>
                                <input type="text" id="nombre_user" name="nombre" required>
                            </div>

                            <div class="form-group">
                                <label for="apellido_user">Apellido:</label>
                                <input type="text" id="apellido_user" name="apellido" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="fecha_na_user">Fecha de Nacimiento:</label>
                            <input type="date" id="fecha_na_user" name="fecha_na" required>
                        </div>

                        <div class="form-group">
                            <label for="correo_user">Correo Electrónico:</label>
                            <input type="email" id="correo_user" name="correo" required>
                        </div>

                        <div class="two-column">
                            <div class="form-group">
                                <label for="usuario_user_reg">Usuario:</label>
                                <input type="text" id="usuario_user_reg" name="usuario" required>
                            </div>

                            <div class="form-group">
                                <label for="contrasena_user_reg">Contraseña:</label>
                                <input type="password" id="contrasena_user_reg" name="contrasena" required>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="cv_upload">Hoja de Vida (PDF):</label>
                            <div class="file-upload">
                                <input type="file" id="cv_upload" name="cv" accept=".pdf" required onchange="updateFileName(this)">
                                <label for="cv_upload" class="file-upload-label" id="file-label">
                                    📄 Seleccionar archivo PDF (máx. 5MB)
                                </label>
                            </div>
                        </div>

                        <button type="submit" class="btn">Registrar Usuario</button>
                        <button type="button" class="btn btn-secondary" onclick="toggleUsuarioForm()">Volver al Login</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="./script/login_script.js"></script>
</body>
</html>