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
            verificarAcceso('Administrador', $tipo_usuario);
            obtenerTramites($conn);
            break;
        case 'POST':
            verificarAcceso('Administrador', $tipo_usuario);
            crearTramite($conn);
            break;
        case 'PUT':
            // print_r($input['orden']);
            if (isset($input['orden']) && $input['orden'] === true) {
                verificarAcceso('Administrador', $tipo_usuario);
                actualizarOrdenTramites($conn);
            } else {
                verificarAcceso('Administrador', $tipo_usuario);
                actualizarTramite($conn);
            }
            break;
        case 'DELETE':
            verificarAcceso('Administrador', $tipo_usuario);
            eliminarTramite($conn);
            break;
        default:
            echo json_encode(["error" => "Método no soportado"]);
            break;
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

// Función para obtener todos los trámites

// function obtenerTramites($conn) {
//     $sql = "SELECT * FROM catalogo_tramites ORDER BY orden ASC";
//     $result = $conn->query($sql);

//     if (!$result) {
//         echo json_encode(["error" => "Error en la consulta SQL: " . $conn->error]);
//         return;
//     }

//     $tramites = [];
//     while ($row = $result->fetch_assoc()) {
//         $tramites[] = $row;
//     }

//     echo json_encode($tramites);
// }
function obtenerTramites($conn) {
    $sql = "SELECT 
              t.*,
              GROUP_CONCAT(r.id) AS requisitos_ids,
              GROUP_CONCAT(r.nombre) AS requisitos_nombres
            FROM catalogo_tramites t
            LEFT JOIN tramite_requisitos tr ON t.id = tr.tramite_id
            LEFT JOIN catalogo_tramites r ON tr.requisito_id = r.id
            GROUP BY t.id
            ORDER BY t.orden ASC";
    
    $result = $conn->query($sql);

    if (!$result) {
        echo json_encode(["error" => "Error en la consulta SQL: " . $conn->error]);
        return;
    }

    $tramites = [];
    while ($row = $result->fetch_assoc()) {
        // Procesar los requisitos
        $requisitos = [];
        if (!empty($row['requisitos_ids'])) {
            $ids = explode(',', $row['requisitos_ids']);
            $nombres = explode(',', $row['requisitos_nombres']);
            
            for ($i = 0; $i < count($ids); $i++) {
                $requisitos[] = [
                    'value' => $ids[$i],
                    'label' => $nombres[$i]
                ];
            }
        }
        
        // Eliminar los campos temporales
        unset($row['requisitos_ids']);
        unset($row['requisitos_nombres']);
        
        // Agregar requisitos al array
        $row['requisitos'] = $requisitos;
        $tramites[] = $row;
    }

    echo json_encode($tramites);
}


// Función para crear un nuevo trámite

function crearTramite($conn) {
    $data = json_decode(file_get_contents("php://input"));

    // Validaciones básicas
    if (!isset($data->nombre, $data->porcentaje_avance)) {
        echo json_encode(["error" => "Datos incompletos"]);
        return;
    }

    $nombre = trim($data->nombre);
    $porcentaje_avance = (int) $data->porcentaje_avance;
    $requisitos = isset($data->requisitos) ? $data->requisitos : [];
    // print_r($requisitos);
    if (empty($nombre)) {
        echo json_encode(["error" => "El campo 'nombre' es obligatorio"]);
        return;
    }
    if ($porcentaje_avance <= 0 || $porcentaje_avance > 100) {
        echo json_encode(["error" => "El 'porcentaje de avance' debe estar entre 1 y 100"]);
        return;
    }

    // Validar que los requisitos existan (si se enviaron)
    if (!empty($requisitos)) {
        // print_r("entro");
        $placeholders = implode(',', array_fill(0, count($requisitos), '?'));
        $sql_check = "SELECT COUNT(*) AS total FROM catalogo_tramites WHERE id IN ($placeholders)";
        $stmt_check = $conn->prepare($sql_check);
        $stmt_check->bind_param(str_repeat('i', count($requisitos)), ...$requisitos);
        $stmt_check->execute();
        $result = $stmt_check->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['total'] != count($requisitos)) {
            echo json_encode(["error" => "Uno o más requisitos no existen"]);
            $stmt_check->close();
            return;
        }
        $stmt_check->close();
    }

    // Obtener el menor valor de 'orden' y restarle 1
    $sql_min_orden = "SELECT MIN(orden) AS min_orden FROM catalogo_tramites";
    $result = $conn->query($sql_min_orden);
    $row = $result->fetch_assoc();
    $nuevo_orden = ($row['min_orden'] !== null) ? $row['min_orden'] - 1 : 0;

    // Iniciar transacción para asegurar integridad
    $conn->begin_transaction();

    try {
        // 1. Insertar el trámite principal
        $sql_tramite = "INSERT INTO catalogo_tramites (nombre, porcentaje_avance, orden) VALUES (?, ?, ?)";
        $stmt_tramite = $conn->prepare($sql_tramite);
        $stmt_tramite->bind_param("sii", $nombre, $porcentaje_avance, $nuevo_orden);
        
        if (!$stmt_tramite->execute()) {
            throw new Exception("Error al registrar el trámite: " . $stmt_tramite->error);
        }

        $tramite_id = $stmt_tramite->insert_id;
        $stmt_tramite->close();

        // 2. Insertar requisitos si existen
        if (!empty($requisitos)) {
            $sql_requisitos = "INSERT INTO tramite_requisitos (tramite_id, requisito_id) VALUES (?, ?)";
            $stmt_requisitos = $conn->prepare($sql_requisitos);
            
            foreach ($requisitos as $requisito_id) {
                $stmt_requisitos->bind_param("ii", $tramite_id, $requisito_id);
                if (!$stmt_requisitos->execute()) {
                    throw new Exception("Error al registrar requisitos: " . $stmt_requisitos->error);
                }
            }
            $stmt_requisitos->close();
        }

        $conn->commit();
        echo json_encode([
            "registrado" => true,
            "id" => $tramite_id,
            "requisitos" => $requisitos
        ]);

    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["error" => $e->getMessage()]);
    }
}


// Función para actualizar un trámite existente

// function actualizarTramite($conn) {
//     // Obtener el ID del trámite desde la URL
//     parse_str($_SERVER['QUERY_STRING'], $query);
//     $id = isset($query['id']) ? (int)$query['id'] : 0;

//     // Validar que el ID es válido
//     if ($id <= 0) {
//         echo json_encode(["error" => "ID de trámite inválido"]);
//         return;
//     }

//     // Obtener los datos del cuerpo de la solicitud
//     $data = json_decode(file_get_contents("php://input"));

//     // Validar la presencia de los campos requeridos
//     if (!isset($data->nombre, $data->porcentaje_avance)) {
//         echo json_encode(["error" => "Datos incompletos"]);
//         return;
//     }

//     $nombre = trim($data->nombre);
//     $porcentaje_avance = (int)$data->porcentaje_avance;
//     $requisitos = isset($data->requisitos) ? $data->requisitos : [];

//     // Validar los campos
//     if (empty($nombre)) {
//         echo json_encode(["error" => "El campo 'nombre' es obligatorio"]);
//         return;
//     }
//     if ($porcentaje_avance <= 0 || $porcentaje_avance > 100) {
//         echo json_encode(["error" => "El 'porcentaje de avance' debe estar entre 1 y 100"]);
//         return;
//     }

//     // Verificar si el trámite existe
//     $check = $conn->prepare("SELECT id FROM catalogo_tramites WHERE id = ?");
//     $check->bind_param("i", $id);
//     $check->execute();
//     $check->store_result();
//     if ($check->num_rows === 0) {
//         echo json_encode(["error" => "El trámite no existe"]);
//         return;
//     }
//     $check->close();

//     // Validar que los requisitos existan (si se enviaron)
//     if (!empty($requisitos)) {
//         $placeholders = implode(',', array_fill(0, count($requisitos), '?'));
//         print_r($placeholders);
//         $sql_check = "SELECT COUNT(*) AS total FROM catalogo_tramites WHERE id IN ($placeholders)";
//         $stmt_check = $conn->prepare($sql_check);
        
//         // Crear tipos de parámetros y valores
//         $types = str_repeat('i', count($requisitos));
//         $stmt_check->bind_param($types, ...$requisitos);
//         $stmt_check->execute();
//         $result = $stmt_check->get_result();
//         $row = $result->fetch_assoc();
//         print_r($requisitos);
//         print_r($row);
//         if ($row['total'] != count($requisitos)) {
//             echo json_encode(["error" => "Uno o más requisitos no existen"]);
//             $stmt_check->close();
//             return;
//         }
//         $stmt_check->close();
//     }

//     // Iniciar transacción
//     $conn->begin_transaction();

//     try {
//         // 1. Actualizar el trámite principal
//         $sql_tramite = "UPDATE catalogo_tramites SET nombre = ?, porcentaje_avance = ? WHERE id = ?";
//         $stmt_tramite = $conn->prepare($sql_tramite);
//         $stmt_tramite->bind_param("sii", $nombre, $porcentaje_avance, $id);
        
//         if (!$stmt_tramite->execute()) {
//             throw new Exception("Error al actualizar el trámite: " . $stmt_tramite->error);
//         }
//         $stmt_tramite->close();

//         // 2. Eliminar todos los requisitos existentes
//         $sql_delete = "DELETE FROM tramite_requisitos WHERE tramite_id = ?";
//         $stmt_delete = $conn->prepare($sql_delete);
//         $stmt_delete->bind_param("i", $id);
        
//         if (!$stmt_delete->execute()) {
//             throw new Exception("Error al eliminar requisitos existentes: " . $stmt_delete->error);
//         }
//         $stmt_delete->close();

//         // 3. Insertar los nuevos requisitos (si hay)
//         if (!empty($requisitos)) {
//             $sql_insert = "INSERT INTO tramite_requisitos (tramite_id, requisito_id) VALUES (?, ?)";
//             $stmt_insert = $conn->prepare($sql_insert);
            
//             foreach ($requisitos as $requisito_id) {
//                 $stmt_insert->bind_param("ii", $id, $requisito_id);
//                 if (!$stmt_insert->execute()) {
//                     throw new Exception("Error al insertar requisito: " . $stmt_insert->error);
//                 }
//             }
//             $stmt_insert->close();
//         }

//         // Confirmar transacción
//         $conn->commit();
//         echo json_encode([
//             "actualizado" => true,
//             "id" => $id,
//             "requisitos" => $requisitos
//         ]);

//     } catch (Exception $e) {
//         // Revertir en caso de error
//         $conn->rollback();
//         echo json_encode(["error" => $e->getMessage()]);
//     }
// }

function actualizarTramite($conn) {
    // Obtener el ID del trámite desde la URL
    parse_str($_SERVER['QUERY_STRING'], $query);
    $id = isset($query['id']) ? (int)$query['id'] : 0;

    // Validar que el ID es válido
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(["error" => "ID de trámite inválido"]);
        return;
    }

    // Obtener los datos del cuerpo de la solicitud
    $data = json_decode(file_get_contents("php://input"));

    // Validar la presencia de los campos requeridos
    if (!isset($data->nombre, $data->porcentaje_avance)) {
        http_response_code(400);
        echo json_encode(["error" => "Datos incompletos"]);
        return;
    }

    $nombre = trim($data->nombre);
    $porcentaje_avance = (int)$data->porcentaje_avance;
    
    // Extraer solo los valores numéricos de los objetos requisitos
    $requisitos = [];
    if (isset($data->requisitos) && is_array($data->requisitos)) {
        foreach ($data->requisitos as $requisito) {
            if (!isset($requisito->value)) {
                http_response_code(400);
                echo json_encode(["error" => "Formato de requisitos inválido"]);
                return;
            }
            $requisitos[] = (int)$requisito->value;
        }
    }

    // Validar los campos
    if (empty($nombre)) {
        http_response_code(400);
        echo json_encode(["error" => "El campo 'nombre' es obligatorio"]);
        return;
    }
    if ($porcentaje_avance < 0 || $porcentaje_avance > 100) {
        http_response_code(400);
        echo json_encode(["error" => "El 'porcentaje de avance' debe estar entre 0 y 100"]);
        return;
    }

    // Verificar si el trámite existe
    $check = $conn->prepare("SELECT id FROM catalogo_tramites WHERE id = ?");
    $check->bind_param("i", $id);
    $check->execute();
    $check->store_result();
    if ($check->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["error" => "El trámite no existe"]);
        return;
    }
    $check->close();

    // Validar que los requisitos existan (si se enviaron)
    if (!empty($requisitos)) {
        // Eliminar posibles duplicados
        $requisitos = array_unique($requisitos);
        
        // Validar autorreferencia
        if (in_array($id, $requisitos)) {
            http_response_code(400);
            echo json_encode(["error" => "Un trámite no puede ser requisito de sí mismo"]);
            return;
        }

        $placeholders = implode(',', array_fill(0, count($requisitos), '?'));
        $sql_check = "SELECT COUNT(*) AS total FROM catalogo_tramites WHERE id IN ($placeholders)";
        $stmt_check = $conn->prepare($sql_check);
        
        $types = str_repeat('i', count($requisitos));
        $stmt_check->bind_param($types, ...$requisitos);
        $stmt_check->execute();
        $result = $stmt_check->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['total'] != count($requisitos)) {
            http_response_code(400);
            echo json_encode(["error" => "Uno o más requisitos no existen"]);
            $stmt_check->close();
            return;
        }
        $stmt_check->close();
    }

    // Iniciar transacción
    $conn->begin_transaction();

    try {
        // 1. Actualizar el trámite principal
        $sql_tramite = "UPDATE catalogo_tramites SET nombre = ?, porcentaje_avance = ? WHERE id = ?";
        $stmt_tramite = $conn->prepare($sql_tramite);
        $stmt_tramite->bind_param("sii", $nombre, $porcentaje_avance, $id);
        
        if (!$stmt_tramite->execute()) {
            throw new Exception("Error al actualizar el trámite: " . $stmt_tramite->error);
        }
        $stmt_tramite->close();

        // 2. Eliminar todos los requisitos existentes
        $sql_delete = "DELETE FROM tramite_requisitos WHERE tramite_id = ?";
        $stmt_delete = $conn->prepare($sql_delete);
        $stmt_delete->bind_param("i", $id);
        
        if (!$stmt_delete->execute()) {
            throw new Exception("Error al eliminar requisitos existentes: " . $stmt_delete->error);
        }
        $stmt_delete->close();

        // 3. Insertar los nuevos requisitos (si hay)
        if (!empty($requisitos)) {
            // Insertar en lote
            $values = implode(',', array_fill(0, count($requisitos), "(?, ?)"));
            $sql_insert = "INSERT INTO tramite_requisitos (tramite_id, requisito_id) VALUES $values";
            $stmt_insert = $conn->prepare($sql_insert);
            
            $params = [];
            foreach ($requisitos as $req_id) {
                $params[] = $id;
                $params[] = $req_id;
            }
            
            $stmt_insert->bind_param(str_repeat('i', count($params)), ...$params);
            if (!$stmt_insert->execute()) {
                throw new Exception("Error al insertar requisitos: " . $stmt_insert->error);
            }
            $stmt_insert->close();
        }

        // Confirmar transacción
        $conn->commit();
        echo json_encode([
            "actualizado" => true,
            "id" => $id,
            "requisitos" => $requisitos
        ]);

    } catch (Exception $e) {
        // Revertir en caso de error
        $conn->rollback();
        http_response_code(500);
        echo json_encode(["error" => $e->getMessage()]);
    }
}

// Función para eliminar un trámite

function eliminarTramite($conn) {
    parse_str($_SERVER['QUERY_STRING'], $query);
    $id = $query['id'];

    // Iniciar transacción
    $conn->begin_transaction();

    try {
        // 1. Primero eliminar los requisitos asociados
        $sql_delete_requisitos = "DELETE FROM tramite_requisitos WHERE tramite_id = ?";
        $stmt = $conn->prepare($sql_delete_requisitos);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // 2. Luego eliminar el trámite principal
        $sql_delete_tramite = "DELETE FROM catalogo_tramites WHERE id = ?";
        $stmt = $conn->prepare($sql_delete_tramite);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        
        // Confirmar transacción
        $conn->commit();
        echo json_encode(["eliminado" => true]);
    } catch (Exception $e) {
        // Revertir en caso de error
        $conn->rollback();
        echo json_encode(["error" => $e->getMessage()]);
    }
}

function actualizarOrdenTramites($conn) {
    $data = json_decode(file_get_contents("php://input"), true);
    
    if (!isset($data['tramites']) || !is_array($data['tramites'])) {
        echo json_encode(["error" => "Datos inválidos"]);
        return;
    }
    
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("UPDATE catalogo_tramites SET orden = ? WHERE id = ?");
        foreach ($data['tramites'] as $index => $tramite) {
            $stmt->bind_param("ii", $index, $tramite['id']);
            $stmt->execute();
        }
        $conn->commit();
        echo json_encode(["success" => true]);
    } catch (Exception $e) {
        $conn->rollback();
        echo json_encode(["error" => "Error al actualizar el orden: " . $e->getMessage()]);
    }
}

?>


