<?php
interface Orders2WhatsApp_Channel_Interface {
    public static function key(): string;
    public function send(array $payload, array $context = []): void;
}
