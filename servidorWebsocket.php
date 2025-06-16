<?php
//el servidor se encargara de gestionar las conexiones y la distribucion de mensajes de los diferentes clientes conectados
use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

require __DIR__ . '/vendor/autoload.php'; //añado el autoloader ya que es necesario para que funcione el servidor

// Clase principal para manejar los websockets
class ServidorPedidos implements MessageComponentInterface //
{
    protected $clientes;
    protected $tipoCliente = [
        'cocina' => [],
        'barra' => [], //no me ha dado tiempo 
        'camarero' => [],
        'cliente' => []
    ];

    public function __construct()
    {
        $this->clientes = new \SplObjectStorage();
        echo "Servidor WebSocket iniciado\n";
    }

    // Cuando un cliente se conecta al servidor
    public function onOpen(ConnectionInterface $conn)
    {
        // Almaceno la nueva conexión
        $this->clientes->attach($conn);
        $conn->tipoCliente = 'desconocido'; // Por defecto, tipo desconocido
        
        // Usando la identificación segura del cliente
        $clienteId = spl_object_hash($conn);
        echo "Nueva conexión: (cliente: {$clienteId})\n";
    }

    // Cuando un cliente envía un mensaje al servidor
    public function onMessage(ConnectionInterface $from, $mensaje)
    {
        $data = json_decode($mensaje, true);
        if ($data === null) {
            echo "Mensaje inválido recibido: {$mensaje}\n";
            return;
        }

        // Primero, verifico si es un mensaje de registro de cliente
        if (isset($data['tipoCliente']) && in_array($data['tipoCliente'], array_keys($this->tipoCliente))) {
            $from->tipoCliente = $data['tipoCliente'];
            $clienteId = spl_object_hash($from);
            $this->tipoCliente[$data['tipoCliente']][$clienteId] = $from;
            echo "Cliente {$clienteId} registrado como: {$data['tipoCliente']}\n";
            return; // No hay nada más que hacer con los mensajes de registro
        }

        // Si no es de registro, debe ser una notificación con un 'tipo'
        if (!isset($data['tipo'])) {
            echo "Mensaje recibido sin 'tipo'. Ignorando: {$mensaje}\n";
            return;
        }

        $tipoMensaje = $data['tipo'];
        $tiposDestino = [];

        echo "Notificación recibida de tipo: {$tipoMensaje}\n";

        switch ($tipoMensaje) {
            case 'productoListo':
            case 'pedidoListo':
                $tiposDestino = ['camarero', 'cocina'];
                break;

            case 'productoServido':
            case 'pedidoServido':
                $tiposDestino = ['cocina', 'camarero'];
                break;
            
            case 'nuevoPedido':
            case 'pedidoEnPreparacion':
                 $tiposDestino = ['cocina', 'camarero']; // Notifico a cocina y camarero
                 break;
        }

        if (!empty($tiposDestino)) {
            echo "-> Reenviando a: " . implode(', ', $tiposDestino) . "\n";
            $this->enviarATodos($mensaje, $tiposDestino, $from);
        } else {
            echo "-> Mensaje de tipo '{$tipoMensaje}' sin destino específico. No se reenvía.\n";
        }
    }

    // Cuando un cliente se desconecta
    public function onClose(ConnectionInterface $conn)
    {
        // Quito la conexión del registro
        $this->clientes->detach($conn);
        
        // Lo quitamos también del registro por tipo
        if (isset($conn->tipoCliente) && $conn->tipoCliente !== 'desconocido') {
            $clienteId = spl_object_hash($conn);
            if (isset($this->clientesTipo[$conn->tipoCliente][$clienteId])) {
                unset($this->clientesTipo[$conn->tipoCliente][$clienteId]);
            }
        }
        
        // Usando la identificación segura del cliente
        $clienteId = spl_object_hash($conn);
        echo "Conexión {$clienteId} cerrada\n";
    }

    // Manejo de errores
    public function onError(ConnectionInterface $conn, \Exception $e)
    {
        echo "Error: {$e->getMessage()}\n";
        $conn->close();
    }
    
    // Método para enviar mensajes a ciertos tipos de clientes, excluyendo al emisor
    protected function enviarATodos($mensaje, array $tiposDestino, ConnectionInterface $emisor = null)
    {
        foreach ($tiposDestino as $tipo) {
            if (isset($this->tipoCliente[$tipo])) {
                foreach ($this->tipoCliente[$tipo] as $cliente) {
                    // No envio el mensaje de vuelta al emisor original
                    if ($emisor !== null && $emisor === $cliente) {
                        continue;
                    }
                    $cliente->send($mensaje);
                }
            }
        }
    }
    
    // Método para enviar mensajes a un cliente específico por número de mesa
    protected function enviarACliente($mensaje, $numMesa)
    {
        if (isset($this->tipoCliente['cliente']) && !empty($this->tipoCliente['cliente'])) {
            foreach ($this->tipoCliente['cliente'] as $cliente) {
                // Verifico que el cliente sea una instancia válida y tenga registrada su mesa
                if ($cliente instanceof ConnectionInterface && isset($cliente->numMesa) && $cliente->numMesa == $numMesa) {
                    $cliente->send($mensaje);
                    // Usando la identificación segura del cliente
                    $clienteId = spl_object_hash($cliente);
                    echo "Mensaje enviado a cliente de mesa {$numMesa} (cliente: {$clienteId})\n";
                }
            }
        }
    }
}

// Creo e inicio el servidor
$ws_port = getenv('WEBSOCKET_PORT') ?: (isset($argv[1]) ? intval($argv[1]) : 80);
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new ServidorPedidos()
        )
    ),
    $ws_port
);

// Mensaje indicando que el servidor esta activo (lo pongo para ver que me conecta correctaemente)
echo "EL  Servidor WebSocket se ha iniciado en el puerto 8081...\n";
$server->run();
