<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Authorization, Content-Type, Access-Control-Allow-Headers, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=utf-8");

// Manejar las solicitudes preflight (OPTIONS)
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Obtener el token JWT desde los encabezados
require 'auth_helpers.php';
require 'decode.php';

$headers = apache_request_headers();
$jwt = isset($headers['Authorization']) ? str_replace('Bearer ', '', $headers['Authorization']) : "";

if ($jwt && validateToken($jwt)) {
    $user_id = extractToken($jwt);
    $tipo_usuario = extractUserType($jwt);

    include "conectar.php";
    $conn = conectarDB();

    if ($conn->connect_error) {
        http_response_code(500);
        echo json_encode(["error" => "Error de conexión: " . $conn->connect_error]);
        exit();
    }

    // Obtener el método HTTP
    $method = $_SERVER['REQUEST_METHOD'];

    switch ($method) {
        case 'GET':
            verificarAcceso('Administrador', $tipo_usuario);
            obtenerLinks($conn);
            break;
        case 'POST':
            verificarAcceso('Administrador', $tipo_usuario);
            // Lógica unificada para crear o actualizar
            crearOActualizarLink($conn);
            break;
        case 'DELETE':
            verificarAcceso('Administrador', $tipo_usuario);
            eliminarLink($conn);
            break;
        default:
            http_response_code(405);
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

// Función para crear directorio de imágenes si no existe
function crearDirectorioImagenes() {
    $directorio = 'images';
    if (!is_dir($directorio)) {
        mkdir($directorio, 0755, true);
    }
    return $directorio;
}

// Función para subir imagen
function subirImagen($archivoImagen) {
    $directorio = crearDirectorioImagenes();
    
    // Validar que sea una imagen
    $tipoArchivo = strtolower(pathinfo($archivoImagen['name'], PATHINFO_EXTENSION));
    $extensionesPermitidas = array('jpg', 'jpeg', 'png', 'gif', 'webp');
    
    if (!in_array($tipoArchivo, $extensionesPermitidas)) {
        throw new Exception('Solo se permiten archivos JPG, JPEG, PNG, GIF y WEBP');
    }
    
    // Validar tamaño (máximo 5MB)
    if ($archivoImagen['size'] > 5000000) {
        throw new Exception('El archivo es demasiado grande. Máximo 5MB');
    }
    
    // Generar nombre único
    $nombreArchivo = uniqid() . '_' . time() . '.' . $tipoArchivo;
    $rutaCompleta = $directorio . '/' . $nombreArchivo;
    
    if (move_uploaded_file($archivoImagen['tmp_name'], $rutaCompleta)) {
        return $rutaCompleta;
    } else {
        throw new Exception('Error al subir la imagen');
    }
}

// Función para obtener la imagen actual de un link
function obtenerImagenActual($conn, $linkId) {
    $sql = "SELECT image_key FROM links WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $linkId);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $imagenActual = null;
    if ($result->num_rows === 1) {
        $link = $result->fetch_assoc();
        $imagenActual = $link['image_key'];
    }
    $stmt->close();
    return $imagenActual;
}

// Función para eliminar imagen del servidor si existe
function eliminarImagenFisica($rutaImagen) {
    if ($rutaImagen && file_exists($rutaImagen) && strpos($rutaImagen, 'images/') !== false) {
        unlink($rutaImagen);
        return true;
    }
    return false;
}

// Función para obtener todos los links
function obtenerLinks($conn) {
    // Verificar si se solicita un link específico por ID
    if (isset($_GET['id'])) {
        $id = intval($_GET['id']);
        $sql = "SELECT id, url, text, image_key, display_order, is_active, created_at FROM links WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 1) {
            $link = $result->fetch_assoc();
            echo json_encode($link);
        } else {
            http_response_code(404);
            echo json_encode(["error" => "Link no encontrado"]);
        }
        $stmt->close();
        return;
    }

    // Obtener todos los links ordenados por display_order
    $sql = "SELECT id, url, text, image_key, display_order, is_active, created_at FROM links ORDER BY display_order ASC";
    $result = $conn->query($sql);

    if (!$result) {
        http_response_code(500);
        echo json_encode(["error" => "Error en la consulta SQL: " . $conn->error]);
        return;
    }

    $links = [];
    while ($row = $result->fetch_assoc()) {
        $links[] = $row;
    }

    echo json_encode($links);
}

// FUNCIÓN UNIFICADA PARA CREAR O ACTUALIZAR LINK
function crearOActualizarLink($conn) {
    // Procesar FormData desde POST
    $id = isset($_POST['id']) ? intval($_POST['id']) : 0;
    $url = $_POST['url'] ?? '';
    $text = $_POST['text'] ?? '';
    $display_order = intval($_POST['display_order'] ?? 0);
    $is_active = isset($_POST['is_active']) ? (bool)$_POST['is_active'] : true;
    $eliminar_imagen = isset($_POST['eliminar_imagen']) ? (bool)$_POST['eliminar_imagen'] : false;
    
    // Validaciones básicas
    if (empty($url) || empty($text)) {
        http_response_code(400);
        echo json_encode(["error" => "URL y text son obligatorios"]);
        return;
    }
    
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        http_response_code(400);
        echo json_encode(["error" => "La URL no tiene un formato válido"]);
        return;
    }
    
    // Si hay ID, es una actualización, sino es una creación
    if ($id > 0) {
        // ACTUALIZAR LINK EXISTENTE
        
        // Verificar que el link exista
        $sqlVerificar = "SELECT id, image_key FROM links WHERE id = ?";
        $stmt = $conn->prepare($sqlVerificar);
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows === 0) {
            http_response_code(404);
            echo json_encode(["error" => "Link no encontrado"]);
            $stmt->close();
            return;
        }
        
        $link = $result->fetch_assoc();
        $imagen_actual = $link['image_key'];
        $stmt->close();
        
        // Manejo de la imagen
        $nueva_imagen = $imagen_actual;
        
        // Si se envía nueva imagen
        if (!empty($_FILES['image']['name'])) {
            try {
                // Eliminar imagen anterior si existe
                if ($imagen_actual) {
                    eliminarImagenFisica($imagen_actual);
                }
                // Subir nueva imagen
                $nueva_imagen = subirImagen($_FILES['image']);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(["error" => $e->getMessage()]);
                return;
            }
        } 
        // Si se solicita eliminar imagen
        elseif ($eliminar_imagen) {
            if ($imagen_actual) {
                eliminarImagenFisica($imagen_actual);
            }
            $nueva_imagen = null;
        }
        
        // Verificar si el display_order ya está en uso por otro link
        $sqlVerificarOrden = "SELECT id FROM links WHERE display_order = ? AND id != ?";
        $stmt = $conn->prepare($sqlVerificarOrden);
        $stmt->bind_param("ii", $display_order, $id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            http_response_code(409);
            echo json_encode(["error" => "Ya existe otro link con el mismo orden de display"]);
            $stmt->close();
            return;
        }
        $stmt->close();
        
        // Actualizar el link
        $sql = "UPDATE links SET url = ?, text = ?, image_key = ?, display_order = ?, is_active = ? WHERE id = ?";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssiii", $url, $text, $nueva_imagen, $display_order, $is_active, $id);

        if ($stmt->execute()) {
            echo json_encode([
                "actualizado" => true,
                "message" => "Link actualizado exitosamente",
                "id" => $id,
                "image_key" => $nueva_imagen
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Error al actualizar el link: " . $conn->error]);
        }

        $stmt->close();
        
    } else {
        // CREAR NUEVO LINK
        
        // Verificar si ya existe un link con el mismo display_order
        $sqlVerificar = "SELECT id FROM links WHERE display_order = ?";
        $stmt = $conn->prepare($sqlVerificar);
        $stmt->bind_param("i", $display_order);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            http_response_code(409);
            echo json_encode(["error" => "Ya existe un link con el mismo orden de display"]);
            $stmt->close();
            return;
        }
        $stmt->close();
        
        // Manejo de la imagen
        $image_key = null;
        if (!empty($_FILES['image']['name'])) {
            try {
                $image_key = subirImagen($_FILES['image']);
            } catch (Exception $e) {
                http_response_code(400);
                echo json_encode(["error" => $e->getMessage()]);
                return;
            }
        }
        
        // Insertar el nuevo link
        $sql = "INSERT INTO links (url, text, image_key, display_order, is_active) VALUES (?, ?, ?, ?, ?)";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("sssii", $url, $text, $image_key, $display_order, $is_active);

        if ($stmt->execute()) {
            $nuevoId = $conn->insert_id;
            http_response_code(201);
            echo json_encode([
                "creado" => true,
                "id" => $nuevoId,
                "message" => "Link creado exitosamente",
                "image_key" => $image_key
            ]);
        } else {
            http_response_code(500);
            echo json_encode(["error" => "Error al crear el link: " . $conn->error]);
        }

        $stmt->close();
    }
}

// Función para eliminar un link
function eliminarLink($conn) {
    if (!isset($_GET['id'])) {
        http_response_code(400);
        echo json_encode(["error" => "ID del link es requerido en la URL"]);
        return;
    }

    $id = intval($_GET['id']);

    // Verificar que el link exista
    $sqlVerificar = "SELECT id, image_key FROM links WHERE id = ?";
    $stmt = $conn->prepare($sqlVerificar);
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        http_response_code(404);
        echo json_encode(["error" => "Link no encontrado"]);
        $stmt->close();
        return;
    }

    $link = $result->fetch_assoc();
    $stmt->close();

    // Eliminar la imagen asociada si existe
    if ($link['image_key']) {
        eliminarImagenFisica($link['image_key']);
    }

    // Eliminar el link
    $sql = "DELETE FROM links WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $id);

    if ($stmt->execute()) {
        echo json_encode(["eliminado" => true, "message" => "Link eliminado exitosamente"]);
    } else {
        http_response_code(500);
        echo json_encode(["error" => "Error al eliminar el link: " . $conn->error]);
    }

    $stmt->close();
}
?>