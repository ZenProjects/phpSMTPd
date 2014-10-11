<?php
/*
 * Serveur d'affichage simpple basé sur les écoutes de connexion libevent.
 *
 * Utilisation :
 * 1) Dans un terminal, exécutez :
 *
 * $ php listener.php 9881
 *
 * 2) Dans un autre terminal, ouvrez une connexion, i.e. :
 *
 * $ nc 127.0.0.1 9881
 *
 * 3) Commencez à taper. Le serveur devrait répéter les entrées.
 */

class MyListenerConnection {
    private $bev, $base;

    public function __destruct() {
        $this->bev->free();
    }

    public function __construct($fd) {
        $this->base = new EventBase();

        $this->bev = new EventBufferEvent($this->base, $fd, EventBufferEvent::OPT_CLOSE_ON_FREE);

        $this->bev->setCallbacks(array($this, "echoReadCallback"), NULL,
            array($this, "echoEventCallback"), NULL);

        if (!$this->bev->enable(Event::READ)) {
            echo "Echec dans l'activation de READ\n";
            return;
        }
	$this->base->dispatch();
    }

    public function echoReadCallback($bev, $ctx) {
        // Copie toutes les données depuis le buffer d'entrée vers le buffer de sortie

        // Variant #1
        $bev->output->addBuffer($bev->input);

        /* Variant #2 */
        /*
        $input    = $bev->getInput();
        $output = $bev->getOutput();
        $output->addBuffer($input);
        */
    }

    public function echoEventCallback($bev, $events, $ctx) {
        if ($events & EventBufferEvent::ERROR) {
            echo "Erreur depuis bufferevent\n";
        }

        if ($events & (EventBufferEvent::EOF | EventBufferEvent::ERROR)) {
            //$bev->free();
            $this->__destruct();
        }
    }
}


new MyListenerConnection(STDIN);
