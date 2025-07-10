<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Process\Process;

class BasthonController extends AbstractController
{
    #[Route('/basthon/session/{id}', name: 'basthon_session')]
    public function startSession(int $id): Response
    {
        $baseDir = $this->getParameter('kernel.project_dir') . '/var/basthon-sessions/session-' . $id;
        $notebookFile = $baseDir . '/notebook.ipynb';

        // Créer le dossier s'il n'existe pas
        if (!is_dir($baseDir)) {
            mkdir($baseDir, 0777, true);
        }

        // Injecter un notebook de base s'il n'existe pas
        if (!file_exists($notebookFile)) {
            file_put_contents($notebookFile, json_encode([
                "cells" => [[
                    "cell_type" => "code",
                    "execution_count" => null,
                    "metadata" => [],
                    "outputs" => [],
                    "source" => ["print('Hello Basthon')"]
                ]],
                "metadata" => [],
                "nbformat" => 4,
                "nbformat_minor" => 2,
            ], JSON_PRETTY_PRINT));
        }

        // Choisir un port aléatoire libre (méthode socket)
        $socket = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr);
        $port = (int) explode(':', stream_socket_get_name($socket, false))[1];
        fclose($socket);

        // Démarrer le conteneur Docker
        $containerName = "basthon-session-$id";

        $dockerCmd = [
            'docker', 'run', '-d',
            '--rm',
            '--name', $containerName,
            '-v', "$baseDir:/home/jovyan/work",
            '-p', "$port:8888",
            'basthon/notebook'
        ];

        $process = new Process($dockerCmd);
        $process->run();

        if (!$process->isSuccessful()) {
            return new Response("Erreur Docker : " . $process->getErrorOutput(), 500);
        }

        // Rendu du template avec iframe
        return $this->render('basthon/session.html.twig', [
            'port' => $port,
            'session_id' => $id,
        ]);
    }
}
