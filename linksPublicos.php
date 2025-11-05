<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Headers: Authorization, Content-Type, Access-Control-Allow-Headers, X-Requested-With");
header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
header("Access-Control-Allow-Credentials: true");
header("Content-Type: application/json; charset=utf-8");

// Manejar solicitudes preflight
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Incluir conexión a BD
include "conectar.php";
$conn = conectarDB();

if ($conn->connect_error) {
    http_response_code(500);
    echo json_encode(["error" => "Error de conexión: " . $conn->connect_error]);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        obtenerLinks($conn);
        break;
    default:
        http_response_code(405);
        echo json_encode(["error" => "Método no soportado"]);
        break;
}

$conn->close();

/**
 * Obtener noticias destacadas (según orden o últimas creadas)
 */
function obtenerLinks($conn) {
    // Verificar si se solicita un link específico por ID
    // if (isset($_GET['id'])) {
    //     $id = intval($_GET['id']);
    //     $sql = "SELECT id, url, text, image_key, display_order, is_active, created_at FROM links WHERE id = ?";
    //     $stmt = $conn->prepare($sql);
    //     $stmt->bind_param("i", $id);
    //     $stmt->execute();
    //     $result = $stmt->get_result();

    //     if ($result->num_rows === 1) {
    //         $link = $result->fetch_assoc();
    //         echo json_encode($link);
    //     } else {
    //         http_response_code(404);
    //         echo json_encode(["error" => "Link no encontrado"]);
    //     }
    //     $stmt->close();
    //     return;
    // }

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
?>
