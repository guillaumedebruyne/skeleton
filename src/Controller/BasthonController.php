<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Process\Process;

class BasthonController extends AbstractController
{
    #[Route('/basthon/session/{id}', name: 'basthon_session_page')]
    public function sessionPage(int $id): Response
    {
        return $this->render('basthon/session.html.twig', [
            'session_id' => $id,
        ]);
    }

    #[Route('/basthon/start/{id}', name: 'start_basthon_session')]
    public function start(int $id): JsonResponse
    {
        $projectDir = $this->getParameter('kernel.project_dir');
        $sessionPath = "$projectDir/var/basthon-sessions/session-$id";

        // 1. Créer le dossier s'il n'existe pas
        if (!is_dir($sessionPath)) {
            mkdir($sessionPath, 0777, true);
        }

        // 2. Injecter un notebook de base s'il n'existe pas
        $notebookPath = "$sessionPath/notebook.ipynb";
        if (!file_exists($notebookPath)) {
            file_put_contents($notebookPath, json_encode([
                "cells" => [[
                    "cell_type" => "code",
                    "execution_count" => null,
                    "metadata" => [],
                    "outputs" => [],
                    "source" => ["print('Hello from Basthon')"]
                ]],
                "metadata" => [],
                "nbformat" => 4,
                "nbformat_minor" => 2,
            ], JSON_PRETTY_PRINT));
        }

        // 3. Trouver un port libre
        $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
        if (!$socket) {
            return new JsonResponse(['error' => 'Impossible de réserver un port libre'], 500);
        }
        $port = (int) explode(':', stream_socket_get_name($socket, false))[1];
        fclose($socket);

        // 4. Démarrer le conteneur Docker
        $containerName = "basthon-session-$id";

// 🔁 1. Vérifier si un conteneur du même nom est déjà lancé
        $checkProcess = new Process(['docker', 'ps', '-q', '-f', "name=$containerName"]);
        $checkProcess->run();

        if (!$checkProcess->isSuccessful()) {
            return new JsonResponse(['error' => 'Impossible de vérifier les conteneurs Docker'], 500);
        }

        $existingContainerId = trim($checkProcess->getOutput());

        if ($existingContainerId !== '') {
            // 🔥 2. Stopper proprement le conteneur existant
            $stopProcess = new Process(['docker', 'stop', $containerName]);
            $stopProcess->run();

            if (!$stopProcess->isSuccessful()) {
                return new JsonResponse(['error' => "Impossible d'arrêter le conteneur existant : $containerName", 'details' => $stopProcess->getErrorOutput()], 500);
            }
        }

        $cmd = [
            'docker', 'run', '-d', '--rm',
            '--name', $containerName,
            '-v', "$sessionPath:/usr/share/nginx/html/work", // 🔁 mount le dossier de session
            '-p', "$port:443",
            'basthon/notebook'
        ];

        $process = new Process($cmd);
        $process->run();


        usleep(700* 1000); // Attendre un peu avant de réessayer

        // 5. Retourne l’URL iframe
        return new JsonResponse([
            'iframe_url' => "https://localhost:$port",
            'session_id' => $id,
            'port' => $port
        ]);
    }
}
