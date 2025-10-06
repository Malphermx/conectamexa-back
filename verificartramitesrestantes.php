<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Authorization, Content-Type, Access-Control-Allow-Headers, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=UTF-8");

if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'auth_helpers.php';
require 'decode.php';
require 'conectar.php';

try {
    // Validar JWT
    $headers = apache_request_headers();
    $jwt = str_replace('Bearer ', '', $headers['Authorization'] ?? '');
    
    if (!$jwt || !validateToken($jwt)) {
        throw new Exception('Acceso no autorizado', 401);
    }

    // Extraer datos del token
    $decodedToken = decodeToken($jwt);
    $user_id = $decodedToken['data']->idTipoUsuario ?? null;
    $tipo_usuario = $decodedToken['data']->idTipoUsuario ?? null;
    
    if (!$user_id) {
        throw new Exception('Token inválido', 401);
    }

    $conn = conectarDB();
    if ($conn->connect_error) {
        throw new Exception("Error de conexión: " . $conn->connect_error, 500);
    }

    switch ($_SERVER['REQUEST_METHOD']) {
        case 'GET':
            handleGetRequest($conn, $user_id);
            break;
            
        default:
            throw new Exception('Método no permitido', 405);
    }

} catch (Exception $e) {
    http_response_code($e->getCode() ?: 500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        // 'trace' => DEBUG_MODE ? $e->getTrace() : null
    ]);
} finally {
    if (isset($conn) && $conn instanceof mysqli) {
        $conn->close();
    }
}

function handleGetRequest($conn, $user_id) {
    // Verificar parámetro requerido
    if (!isset($_GET['proyecto_id'])) {
        throw new Exception('Parámetro proyecto_id requerido', 400);
    }

    $proyecto_id = filter_var($_GET['proyecto_id'], FILTER_VALIDATE_INT);
    if (!$proyecto_id || $proyecto_id <= 0) {
        throw new Exception('ID de proyecto inválido', 400);
    }

    // Verificar permisos del usuario
    $stmt = $conn->prepare("
        SELECT COUNT(*) 
        FROM proyecto_usuarios 
        WHERE proyecto_id = ?
    ");
    $stmt->bind_param("i", $proyecto_id);
    $stmt->execute();
    
    // Obtener resultado correctamente
    $result = $stmt->get_result();
    $row = $result->fetch_row();
    // print_r($row);
    if ($row[0] == 0) {
        throw new Exception('Acceso denegado al proyecto', 403);
    }
    
    // Limpiar resultados anteriores
    $stmt->close();

    // Obtener documentos faltantes
    $documentosFaltantes = getDocumentosFaltantes($conn, $proyecto_id);
    
    echo json_encode([
        'success' => true,
        'data' => $documentosFaltantes
    ]);
}

function getDocumentosFaltantes($conn, $proyecto_id) {
    try {
        // 1. Validar y obtener proyecto
        $proyecto = obtenerProyecto($conn, $proyecto_id);
        $basePath = construirRutaProyecto($proyecto);

        // 2. Obtener trámites asignados al proyecto
        $tramitesProyecto = obtenerTramitesProyecto($conn, $proyecto_id);
        
        // 3. Procesar cada trámite y sus requisitos
        $tramitesCompletos = [];
        
        foreach ($tramitesProyecto as $tramite) {
            $tramiteId = $tramite['id'];
            
            // Ruta del trámite (carpeta principal)
            $tramitePath = "{$basePath}/tramite_{$tramiteId}/";
            
            // Verificar documentos en el trámite (carpeta principal)
            $documentosTramite = verificarDocumentos($tramitePath);
            
            // Procesar requisitos
            $requisitos = obtenerRequisitosTramite($conn, $tramiteId);
            $requisitosProcesados = [];
            $todosRequisitosCompletos = true;
            
            foreach ($requisitos as $requisito) {
                $requisitoPath = "{$tramitePath}/requisito_{$requisito['id']}/";
                $documentosRequisito = verificarDocumentos($requisitoPath);
                
                $requisitosProcesados[] = [
                    'id' => $requisito['id'],
                    'nombre' => $requisito['nombre'],
                    'completado' => $documentosRequisito,
                    'ruta' => $requisitoPath
                ];
                
                $todosRequisitosCompletos = $todosRequisitosCompletos && $documentosRequisito;
            }
            
            // Un trámite se considera completo si tiene documentos en su carpeta Y todos sus requisitos están completos
            $tramiteCompleto = $documentosTramite && $todosRequisitosCompletos;
            
            $tramitesCompletos[] = [
                'id' => $tramiteId,
                'nombre' => $tramite['nombre'],
                'orden' => $tramite['orden'],
                'documentos_en_tramite' => $documentosTramite,
                'completado' => $tramiteCompleto,
                'requisitos' => $requisitosProcesados
            ];
        }

        // 4. Calcular métricas
        $metricas = calcularMetricasCompletas($tramitesCompletos);
        
        return [
            'proyecto' => [
                'id' => $proyecto_id,
                'nombre' => $proyecto['nombre'],
                'completo' => $metricas['completo']
            ],
            'tramites' => $tramitesCompletos,
            'metricas' => $metricas
        ];

    } catch (Exception $e) {
        throw new Exception("Error obteniendo documentos faltantes: " . $e->getMessage());
    }
}

// Funciones auxiliares
function obtenerProyecto($conn, $proyecto_id) {
    $stmt = $conn->prepare("SELECT id, nombre FROM proyectos WHERE id = ?");
    $stmt->bind_param("i", $proyecto_id);
    $stmt->execute();
    $result = $stmt->get_result()->fetch_assoc();
    if (!$result) throw new Exception("Proyecto no encontrado");
    return $result;
}

function construirRutaProyecto($proyecto) {
    // $nombreSanitizado = preg_replace('/[^a-z0-9]/i', '_', $proyecto['nombre']);
    // $nombreSanitizado = preg_replace('/[^a-zA-Z0-9-_]/', '', $proyecto['nombre']);
    $nombreSanitizado = $proyecto['nombre'];
    return "archivos/{$proyecto['id']}_{$nombreSanitizado}";
}

function obtenerTramitesProyecto($conn, $proyecto_id) {
    $stmt = $conn->prepare("
        SELECT 
            pt.tramite_id AS id, 
            ct.nombre, 
            ct.orden 
        FROM proyecto_tramites pt
        INNER JOIN catalogo_tramites ct ON pt.tramite_id = ct.id
        WHERE pt.proyecto_id = ?
        ORDER BY ct.orden
    ");
    $stmt->bind_param("i", $proyecto_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function obtenerRequisitosTramite($conn, $tramite_id) {
    $stmt = $conn->prepare("
        SELECT 
            tr.requisito_id AS id, 
            ct.nombre 
        FROM tramite_requisitos tr
        INNER JOIN catalogo_tramites ct ON tr.requisito_id = ct.id
        WHERE tr.tramite_id = ?
        ORDER BY ct.orden
    ");
    $stmt->bind_param("i", $tramite_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function verificarDocumentos($path) {
    return is_dir($path) && count(glob("{$path}*")) > 0;
}

function calcularMetricasCompletas($tramites) {
    $totalTramites = count($tramites);
    $tramitesCompletados = array_filter($tramites, fn($t) => $t['completado']);
    
    $requisitosFaltantes = array_reduce(
        $tramites,
        function($total, $t) {
            return $total + count(array_filter($t['requisitos'], fn($r) => !$r['completado']));
        },
        0
    );
    
    return [
        'total_tramites' => $totalTramites,
        'tramites_completados' => count($tramitesCompletados),
        'requisitos_faltantes' => $requisitosFaltantes,
        'tramites_con_documentos' => count(array_filter($tramites, fn($t) => $t['documentos_en_tramite'])),
        'completo' => $totalTramites > 0 && (count($tramitesCompletados) === $totalTramites)
    ];
}
