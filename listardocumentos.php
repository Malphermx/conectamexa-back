<?php
header("Access-Control-Allow-Origin: *");  
header("Access-Control-Allow-Headers: Authorization, Content-Type, Access-Control-Allow-Headers, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");  
header("Access-Control-Allow-Credentials: true");

// Manejar las solicitudes preflight (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

require 'auth_helpers.php';
require 'decode.php';

// Validar token JWT
$headers = apache_request_headers();
$jwt = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : "";

if ($jwt && validateToken($jwt)) {
    $user_id = extractToken($jwt);
    $tipo_usuario = extractUserType($jwt);

    $method = $_SERVER['REQUEST_METHOD'];
    $input = json_decode(file_get_contents("php://input"), true);

    include "conectar.php";
    $conn = conectarDB();
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    switch ($method) {
        case 'GET':
            // Endpoint para listar proyectos de usuario normal
            if (isset($_GET['listar_proyectos_usuario']) && $tipo_usuario == 2) {
                // print_r($_GET['listar_proyectos_usuario']);
                listarProyectosUsuario($conn, $_GET['listar_proyectos_usuario']);
            } 
            // Endpoint original para documentos (solo admin)
            elseif (isset($_GET['proyecto_id'])) {
                // verificarAcceso('Administrador', $tipo_usuario);
                obtenerDocumentos($conn);
            } else {
                echo json_encode(["error" => "Parámetros inválidos"]);
            }
            break;
        case 'DELETE':
            verificarAcceso('Administrador', $tipo_usuario);
            eliminarDocumento($conn);
            break;
        default:
            echo json_encode(["error" => "Método no permitido"]);
    }

    $conn->close();

} else {
    http_response_code(401);
    echo json_encode(array("message" => "Unauthorized"));
}

function verificarAcceso($rolRequerido, $tipoUsuarioActual) {
    switch($tipoUsuarioActual){
        case "2":
            $tipoUsuarioActual = 'Usuario';
            break;
        default:
            $tipoUsuarioActual = 'Administrador';
    }
    if ($tipoUsuarioActual !== $rolRequerido) {
        http_response_code(403);
        echo json_encode(["error" => "Acceso denegado"]);
        exit();
    }
}

function extractUserType($jwt) {
    $decoded = decodeToken($jwt); 
    return $decoded['data']->idTipoUsuario ?? null; 
}

/**
 * Lista los proyectos de un usuario normal (tipo usuario)
 */

function listarProyectosUsuario($conn, $user_id) {
    try {
        // Validación reforzada
        if (!is_numeric($user_id) || $user_id <= 0) {
            throw new InvalidArgumentException("ID de usuario inválido: " . $user_id);
        }

        // Consulta SQL optimizada
        $sql = "SELECT 
                    p.id,
                    p.nombre,
                    p.fecha_creacion,
                    p.porcentaje_real,
                    p.url_imagen,
                    COUNT(DISTINCT a.id) AS documentos,
                    COUNT(DISTINCT pu.usuario_id) AS colaboradores,
                    COUNT(DISTINCT pt.tramite_id) AS tramites
                FROM proyecto_usuarios pu
                INNER JOIN proyectos p ON pu.proyecto_id = p.id
                LEFT JOIN proyecto_tramites pt ON p.id = pt.proyecto_id
                LEFT JOIN archivos a ON p.id = a.proyecto_id
                WHERE pu.usuario_id = ?
                GROUP BY p.id
                ORDER BY p.fecha_creacion DESC";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        
        // Manejo de resultados seguro
        $result = $stmt->get_result();
        $proyectos = $result->fetch_all(MYSQLI_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'proyectos' => $proyectos ?: [],
                'meta' => [
                    'total' => count($proyectos),
                    'user_id' => $user_id
                ]
            ]
        ]);
        
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

function obtenerDocumentos($conn) {
    try {
        $proyecto_id = isset($_GET['proyecto_id']) ? (int)$_GET['proyecto_id'] : null;

        if (!$proyecto_id) {
            http_response_code(400);
            echo json_encode(["error" => "El parámetro 'proyecto_id' es requerido"]);
            exit();
        }

        $sql = "
            SELECT 
                a.id,
                a.url,
                a.fecha_creacion,
                a.tramite_id,
                ct.nombre AS tramite_nombre,
                ct.orden AS tramite_orden,
                a.requisito_id,
                cr.nombre AS requisito_nombre,
                p.nombre AS proyecto_nombre
            FROM archivos a
            LEFT JOIN catalogo_tramites ct ON a.tramite_id = ct.id
            LEFT JOIN catalogo_tramites cr ON a.requisito_id = cr.id
            JOIN proyectos p ON a.proyecto_id = p.id
            WHERE a.proyecto_id = ?
            ORDER BY ct.orden ASC, cr.orden ASC
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $proyecto_id);
        $stmt->execute();
        $result = $stmt->get_result();

        $documentos = [];
        while ($row = $result->fetch_assoc()) {
            $documento = [
                'id' => $row['id'],
                'nombre_archivo' => basename($row['url']),
                'ruta' => $row['url'],
                'fecha' => $row['fecha_creacion'],
                'proyecto' => [
                    'id' => $proyecto_id,
                    'nombre' => $row['proyecto_nombre']
                ],
                'tramite' => [
                    'id' => $row['tramite_id'],
                    'nombre' => $row['tramite_nombre'],
                    'orden' => $row['tramite_orden']
                ],
                'tipo' => $row['requisito_id'] ? 'requisito' : 'tramite'
            ];

            if ($row['requisito_id']) {
                $documento['requisito'] = [
                    'id' => $row['requisito_id'],
                    'nombre' => $row['requisito_nombre']
                ];
            }

            $documentos[] = $documento;
        }

        echo json_encode([
            'success' => true,
            'proyecto_id' => $proyecto_id,
            'documentos' => $documentos,
            'total' => count($documentos)
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "Error al listar documentos",
            "details" => $e->getMessage()
        ]);
    }
}

function eliminarDocumento($conn) {
    try {
        // Obtener datos del cuerpo de la solicitud
        $data = json_decode(file_get_contents("php://input"), true);
        $documento_id = isset($data['id']) ? (int)$data['id'] : null;

        if (!$documento_id) {
            http_response_code(400);
            echo json_encode(["error" => "Se requiere el ID del documento"]);
            exit();
        }

        // 1. Obtener información completa del documento
        $sql_select = "SELECT url, proyecto_id, tramite_id, requisito_id 
                      FROM archivos 
                      WHERE id = ?";
        $stmt_select = $conn->prepare($sql_select);
        $stmt_select->bind_param("i", $documento_id);
        $stmt_select->execute();
        $result = $stmt_select->get_result();
        $documento = $result->fetch_assoc();

        if (!$documento) {
            http_response_code(404);
            echo json_encode(["error" => "Documento no encontrado"]);
            exit();
        }

        $proyecto_id = $documento['proyecto_id'];
        $tramite_id = $documento['tramite_id'];

        // 2. Eliminar de la base de datos
        $sql_delete = "DELETE FROM archivos WHERE id = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i", $documento_id);
        $stmt_delete->execute();

        // 3. Eliminar el archivo físico
        $ruta_archivo = $documento['url'];
        if (file_exists($ruta_archivo)) {
            unlink($ruta_archivo);
        }

        // 4. Actualizar porcentaje del proyecto
        $nuevo_porcentaje = actualizarPorcentajeProyecto($conn, $proyecto_id);

        echo json_encode([
            "success" => true,
            "message" => "Documento eliminado correctamente",
            "nuevo_porcentaje" => $nuevo_porcentaje,
            "deleted_id" => $documento_id
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            "error" => "Error al eliminar documento",
            "details" => $e->getMessage()
        ]);
    }
}

function actualizarPorcentajeProyecto($conn, $proyecto_id) {
    $porcentaje_total = 0;
    
    // Obtener todos los trámites del proyecto
    $tramites = obtenerTramitesProyecto($conn, $proyecto_id);
    
    foreach ($tramites as $tramite) {
        $tramite_id = $tramite['id'];
        
        // Verificar si el trámite está completo
        if (estaTramiteCompleto($conn, $proyecto_id, $tramite_id)) {
            $porcentaje_total += obtenerPorcentajeTramite($conn, $tramite_id);
        }
    }
    
    // Actualizar proyecto
    $sql = "UPDATE proyectos SET porcentaje_avance = ? WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("di", $porcentaje_total, $proyecto_id);
    $stmt->execute();
    
    return $porcentaje_total;
}

function estaTramiteCompleto($conn, $proyecto_id, $tramite_id) {
    // 1. Verificar si el trámite tiene documentos
    $sql_tramite = "SELECT COUNT(*) 
                   FROM archivos 
                   WHERE proyecto_id = ? 
                   AND tramite_id = ? 
                   AND requisito_id IS NULL";
    $stmt = $conn->prepare($sql_tramite);
    $stmt->bind_param("ii", $proyecto_id, $tramite_id);
    $stmt->execute();
    $doc_tramite = $stmt->get_result()->fetch_row()[0];
    
    if ($doc_tramite == 0) return false;
    
    // 2. Verificar requisitos del trámite
    $requisitos = obtenerRequisitosTramite($conn, $tramite_id);
    
    // Si no tiene requisitos, solo necesita documentos en el trámite
    if (count($requisitos) == 0) return true;
    
    // 3. Verificar documentos en requisitos
    foreach ($requisitos as $requisito) {
        $sql_requisito = "SELECT COUNT(*) 
                         FROM archivos 
                         WHERE proyecto_id = ? 
                         AND tramite_id = ? 
                         AND requisito_id = ?";
        $stmt = $conn->prepare($sql_requisito);
        $stmt->bind_param("iii", $proyecto_id, $tramite_id, $requisito['id']);
        $stmt->execute();
        $doc_requisito = $stmt->get_result()->fetch_row()[0];
        
        if ($doc_requisito == 0) return false;
    }
    
    return true;
}

// Funciones auxiliares
function obtenerTramitesProyecto($conn, $proyecto_id) {
    $sql = "SELECT tramite_id AS id 
           FROM proyecto_tramites 
           WHERE proyecto_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $proyecto_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}

function obtenerPorcentajeTramite($conn, $tramite_id) {
    $sql = "SELECT porcentaje_avance 
           FROM catalogo_tramites 
           WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $tramite_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_row()[0];
}

function obtenerRequisitosTramite($conn, $tramite_id) {
    $sql = "SELECT requisito_id AS id 
           FROM tramite_requisitos 
           WHERE tramite_id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $tramite_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
}
?>