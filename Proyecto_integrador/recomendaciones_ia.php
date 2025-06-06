<?php
session_start();

// Verificar sesión
if (!isset($_SESSION['tipo_usuario']) || $_SESSION['tipo_usuario'] !== 'usuario') {
    header('Location: index.php');
    exit;
}

require 'conexion_bd.php';
$user_id = $_SESSION['id'];

// Configuración de la API
$api_key = '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';
$api_url = 'https://openrouter.ai/api/v1/chat/completions';
$model = 'deepseek/deepseek-chat-v3-0324:free';

// Función para extraer texto del PDF
function extraerTextoPDF($contenidoPDF) {
    // Intentar usar diferentes métodos para extraer texto
    $texto = '';
    
    // Método básico - buscar texto plano en el PDF
    if (preg_match_all('/\((.*?)\)/', $contenidoPDF, $matches)) {
        $texto = implode(' ', $matches[1]);
    }
    
    // Si no se encuentra texto, buscar patrones de texto comunes
    if (empty($texto) && preg_match_all('/BT\s+.*?ET/s', $contenidoPDF, $matches)) {
        foreach ($matches[0] as $match) {
            if (preg_match_all('/\[(.*?)\]/', $match, $textMatches)) {
                $texto .= ' ' . implode(' ', $textMatches[1]);
            }
        }
    }
    
    // Limpiar el texto extraído
    $texto = preg_replace('/[^\w\s\.\,\-\@\(\)]/', ' ', $texto);
    $texto = preg_replace('/\s+/', ' ', $texto);
    
    return trim($texto);
}

// Función para llamar a la API de DeepSeek
function analizarCVconIA($textoCv, $ofertas) {
    global $api_key, $api_url, $model;
    
    // Preparar información de ofertas para el análisis
    $ofertasTexto = '';
    foreach ($ofertas as $oferta) {
        $ofertasTexto .= "- {$oferta['puesto']} en {$oferta['nombre_empresa']}\n";
        $ofertasTexto .= "  Ubicación: {$oferta['lugar']}\n";
        $ofertasTexto .= "  Salario: $" . number_format($oferta['salario']) . "\n";
        $ofertasTexto .= "  Contrato: {$oferta['contrato']}\n";
        $ofertasTexto .= "  Descripción: {$oferta['descripcion']}\n";
        $ofertasTexto .= "  Requisitos: {$oferta['requerimiento']}\n\n";
    }
    
    $prompt = "Eres un experto en recursos humanos y análisis de CV. Analiza el siguiente curriculum vitae y proporciona recomendaciones basándote en las ofertas de trabajo disponibles.

CURRICULUM VITAE:
{$textoCv}

OFERTAS DE TRABAJO DISPONIBLES:
{$ofertasTexto}

Por favor, proporciona un análisis estructurado que incluya:

1. ANÁLISIS DEL PERFIL PROFESIONAL:
- Fortalezas identificadas
- Áreas de mejora
- Experiencia relevante
- Habilidades técnicas destacadas

2. RECOMENDACIONES DE OFERTAS (máximo 3):
Para cada oferta recomendada, indica:
- Nombre del puesto y empresa
- Porcentaje de compatibilidad (1-100%)
- Razones específicas de la recomendación
- Qué aspectos del CV coinciden con los requisitos

3. CONSEJOS PARA MEJORAR EL PERFIL:
- Habilidades que debería desarrollar
- Certificaciones o cursos recomendados
- Experiencias que le ayudarían

Mantén un tono profesional y constructivo. Si el CV tiene poca información, menciona qué elementos debería incluir.";

    $data = [
        'model' => $model,
        'messages' => [
            [
                'role' => 'user',
                'content' => $prompt
            ]
        ],
        'max_tokens' => 2000,
        'temperature' => 0.7
    ];

    $options = [
        'http' => [
            'header' => [
                "Content-Type: application/json",
                "Authorization: Bearer {$api_key}",
                "HTTP-Referer: localhost",
                "X-Title: Sistema de Recomendaciones CV"
            ],
            'method' => 'POST',
            'content' => json_encode($data)
        ]
    ];

    $context = stream_context_create($options);
    $response = file_get_contents($api_url, false, $context);
    
    if ($response === FALSE) {
        return ['error' => 'Error al conectar con la API de análisis'];
    }

    $result = json_decode($response, true);
    
    if (isset($result['choices'][0]['message']['content'])) {
        return ['analisis' => $result['choices'][0]['message']['content']];
    } else {
        return ['error' => 'Error en la respuesta de la API: ' . json_encode($result)];
    }
}

try {
    // Obtener datos del usuario
    $stmt = $pdo->prepare("SELECT * FROM usuario WHERE idusuario = ?");
    $stmt->execute([$user_id]);
    $usuario = $stmt->fetch(PDO::FETCH_ASSOC);

    // Obtener CV del usuario
    $stmt = $pdo->prepare("SELECT * FROM cv WHERE idusuario = ?");
    $stmt->execute([$user_id]);
    $cv = $stmt->fetch(PDO::FETCH_ASSOC);

    // Obtener ofertas activas
    $stmt = $pdo->prepare("SELECT ot.*, e.nombre_empresa, e.logo
        FROM ofertas_trabajo ot
        JOIN empresa e ON ot.idempresa = e.idempresa
        WHERE ot.activa = 1
        ORDER BY ot.fecha_creacion DESC");
    $stmt->execute();
    $ofertas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $analisisIA = null;
    $error = null;

    if ($cv && !empty($ofertas)) {
        // Extraer texto del CV
        $textoCv = extraerTextoPDF($cv['archivo']);
        
        if (empty($textoCv)) {
            $textoCv = "CV subido pero no se pudo extraer el texto automáticamente. 
                       Usuario: {$usuario['nombre']} {$usuario['apellido']}
                       Email: {$usuario['correro']}
                       Fecha de nacimiento: {$usuario['fecha_na']}";
        }
        
        // Analizar con IA
        $resultado = analizarCVconIA($textoCv, $ofertas);
        
        if (isset($resultado['error'])) {
            $error = $resultado['error'];
        } else {
            $analisisIA = $resultado['analisis'];
        }
    }

} catch (PDOException $e) {
    $error = "Error en la base de datos: " . $e->getMessage();
} catch (Exception $e) {
    $error = "Error general: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Recomendaciones IA - <?php echo htmlspecialchars($usuario['nombre']); ?></title>
    <link rel="stylesheet" href="./css/dashboard_usuario.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
</head>
<body>
    <div class="recomendaciones-container">
        <a href="dashboard_usuario.php" class="btn-volver">
            <i class="fas fa-arrow-left"></i> Volver al Dashboard
        </a>
        
        <div class="analisis-card">
            <div class="analisis-header">
                <h1><i class="fas fa-robot"></i> Análisis Inteligente de tu CV</h1>
                <p>Recomendaciones personalizadas basadas en Inteligencia Artificial</p>
            </div>
            
            <?php if ($error): ?>
                <div class="analisis-content">
                    <div class="error-message">
                        <i class="fas fa-exclamation-triangle"></i> 
                        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                    </div>
                </div>
            <?php elseif (!$cv): ?>
                <div class="analisis-content">
                    <div class="warning-message">
                        <i class="fas fa-file-upload"></i> 
                        <strong>CV Requerido:</strong> Para obtener recomendaciones personalizadas, necesitas subir tu curriculum vitae primero.
                        <br><br>
                        <a href="dashboard_usuario.php#cv" class="btn btn-primary">
                            <i class="fas fa-upload"></i> Subir mi CV
                        </a>
                    </div>
                </div>
            <?php elseif (empty($ofertas)): ?>
                <div class="analisis-content">
                    <div class="warning-message">
                        <i class="fas fa-briefcase"></i> 
                        <strong>Sin Ofertas:</strong> No hay ofertas de trabajo disponibles en este momento para realizar el análisis.
                    </div>
                </div>
            <?php elseif ($analisisIA): ?>
                <div class="analisis-content">
                    <div class="stats-grid">
                        <div class="stat-card">
                            <span class="stat-number"><?php echo count($ofertas); ?></span>
                            <div class="stat-label">Ofertas Analizadas</div>
                        </div>
                        <div class="stat-card">
                            <span class="stat-number"><i class="fas fa-robot"></i></span>
                            <div class="stat-label">Análisis con IA</div>
                        </div>
                        <div class="stat-card">
                            <span class="stat-number"><?php echo date('H:i'); ?></span>
                            <div class="stat-label">Hora de Análisis</div>
                        </div>
                    </div>
                    
                    <div class="analisis-ia-content">
                        <?php 
                        // Convertir el análisis a HTML manteniendo la estructura
                        $analisisHTML = htmlspecialchars($analisisIA);
                        
                        // Convertir títulos principales (números seguidos de punto y mayúsculas)
                        $analisisHTML = preg_replace('/(\d+\.\s+[A-ZÁÉÍÓÚÑ\s]+:)/', '<h2>$1</h2>', $analisisHTML);
                        
                        // Convertir subtítulos (- seguido de texto en mayúsculas)
                        $analisisHTML = preg_replace('/(-\s+[A-Za-záéíóúñÁÉÍÓÚÑ\s]+:)/', '<h3>$1</h3>', $analisisHTML);
                        
                        // Convertir líneas que empiezan con - en elementos de lista
                        $analisisHTML = preg_replace('/^-\s+(.+)$/m', '<li>$1</li>', $analisisHTML);
                        
                        // Agrupar elementos de lista consecutivos
                        $analisisHTML = preg_replace('/(<li>.*?<\/li>)\s*(?=<li>)/s', '$1', $analisisHTML);
                        $analisisHTML = preg_replace('/(<li>.*?<\/li>)/s', '<ul>$1</ul>', $analisisHTML);
                        
                        // Limpiar listas duplicadas
                        $analisisHTML = preg_replace('/<\/ul>\s*<ul>/', '', $analisisHTML);
                        
                        // Convertir saltos de línea en párrafos
                        $analisisHTML = preg_replace('/\n\s*\n/', '</p><p>', $analisisHTML);
                        $analisisHTML = '<p>' . $analisisHTML . '</p>';
                        
                        // Limpiar párrafos vacíos
                        $analisisHTML = preg_replace('/<p>\s*<\/p>/', '', $analisisHTML);
                        
                        // Resaltar porcentajes de compatibilidad
                        $analisisHTML = preg_replace('/(\d+%\s*(?:de\s+)?compatibilidad)/i', '<span class="compatibilidad">$1</span>', $analisisHTML);
                        
                        echo $analisisHTML;
                        ?>
                    </div>
                    
                    <hr style="margin: 30px 0; border: 1px solid #eee;">
                    
                    <div style="background: #f8f9fa; padding: 20px; border-radius: 5px; margin-top: 30px;">
                        <h3><i class="fas fa-info-circle"></i> Nota Importante</h3>
                        <p style="margin-bottom: 0;">
                            Este análisis ha sido generado por Inteligencia Artificial basándose en tu CV y las ofertas disponibles. 
                            Las recomendaciones son orientativas y pueden variar según factores adicionales no incluidos en el análisis automático.
                            Te recomendamos revisar personalmente cada oferta antes de aplicar.
                        </p>
                    </div>
                </div>
            <?php else: ?>
                <div class="analisis-content">
                    <div class="loading">
                        <i class="fas fa-spinner"></i>
                        <p>Analizando tu CV con Inteligencia Artificial...</p>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        
        <?php if ($cv && !empty($ofertas) && !$error): ?>
            <div class="analisis-card">
                <div style="padding: 20px; text-align: center; background: #f8f9fa;">
                    <h3><i class="fas fa-sync-alt"></i> ¿Quieres un nuevo análisis?</h3>
                    <p>Si has actualizado tu CV o hay nuevas ofertas disponibles, puedes generar un análisis actualizado.</p>
                    <button onclick="location.reload()" class="btn btn-primary">
                        <i class="fas fa-robot"></i> Generar Nuevo Análisis
                    </button>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        // Auto-refresh cada 30 segundos si está cargando
        if (document.querySelector('.loading')) {
            setTimeout(() => {
                location.reload();
            }, 30000);
        }
        
        // Smooth scroll para enlaces internos
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth',
                        block: 'start'
                    });
                }
            });
        });
    </script>
</body>
</html>