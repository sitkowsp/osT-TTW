<?php
/**
 * osTicket Ticket Webhook Plugin
 *
 * Sends an HTTP POST webhook with JSON ticket details when a new ticket
 * is created in selected departments. Supports Basic Auth, multi-instance
 * configuration, and department filtering.
 *
 * @see https://github.com/osTicket/osTicket
 */
return array(
    'id'          => 'osticket:ticket-webhook',
    'version'     => '1.0.0',
    'name'        => 'Ticket Webhook',
    'author'      => 'osTicket Community',
    'description' => 'Sends an HTTP POST webhook with JSON ticket details when a new ticket is created in selected departments. Supports Basic Auth, multi-instance configuration, and department filtering.',
    'url'         => 'https://github.com/osticket-contrib/osticket-ticket-webhook',
    'ost_version' => '1.18',
    'plugin'      => 'include/class.ticket-webhook.php:TicketWebhookPlugin',
);
