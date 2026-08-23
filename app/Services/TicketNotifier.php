<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\User;
use App\Notifications\TicketOpenedNotification;
use App\Notifications\TicketRepliedNotification;
use Throwable;

/**
 * Support ticket emails.
 *
 * Best-effort like the order emails: a mail failure must never stop a customer
 * from raising a ticket or a shop from answering one.
 */
class TicketNotifier
{
    public function ticketOpened(Ticket $ticket): void
    {
        $body = $ticket->messages()->value('body') ?? '';

        // Acknowledge to the customer so they know it arrived.
        if ($customer = $ticket->user) {
            $this->send(
                fn () => $customer->notify(new TicketOpenedNotification($ticket, $body, 'customer')),
                "ticket acknowledgement for {$ticket->reference}",
            );
        }

        foreach ($this->shopSide($ticket) as $recipient) {
            $this->send(
                fn () => $recipient->notify(new TicketOpenedNotification($ticket, $body, 'shop')),
                "new ticket alert to {$recipient->email} for {$ticket->reference}",
            );
        }
    }

    public function ticketReplied(Ticket $ticket, User $author, string $body): void
    {
        foreach ($this->recipientsFor($ticket, $author) as $recipient) {
            $this->send(
                fn () => $recipient->notify(new TicketRepliedNotification($ticket, $body, $author->name)),
                "ticket reply alert to {$recipient->email} for {$ticket->reference}",
            );
        }
    }

    /**
     * Everyone on the conversation except whoever just wrote.
     *
     * @return array<int, User>
     */
    private function recipientsFor(Ticket $ticket, User $author): array
    {
        $recipients = $ticket->seller_id === $author->id || $author->isAdmin()
            ? [$ticket->user]
            : $this->shopSide($ticket);

        return array_values(array_filter($recipients, fn (?User $user) => $user && $user->email && $user->id !== $author->id));
    }

    /**
     * The seller who owns the ticket, or the administrators when it is a
     * platform-level request with no shop attached.
     *
     * @return array<int, User>
     */
    private function shopSide(Ticket $ticket): array
    {
        if ($ticket->seller) {
            return [$ticket->seller];
        }

        return User::whereIn('role', ['admin', 'master'])->where('is_active', true)->get()->all();
    }

    private function send(callable $callback, string $description): void
    {
        try {
            $callback();
        } catch (Throwable $exception) {
            report($exception);
            logger()->warning("Failed to send {$description}: {$exception->getMessage()}");
        }
    }
}
