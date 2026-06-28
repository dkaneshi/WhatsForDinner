<?php

namespace App\Notifications;

use App\Models\FamilyInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class FamilyInvitationNotification extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     */
    public function __construct(
        public FamilyInvitation $invitation,
        public string $token,
    ) {}

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        $this->invitation->loadMissing(['family', 'inviter']);

        return (new MailMessage)
            ->subject(__('You have been invited to :family', ['family' => $this->invitation->family->name]))
            ->greeting(__('You are invited!'))
            ->line(__(':name invited you to join :family on What\'s for Dinner?', [
                'name' => $this->invitation->inviter->name,
                'family' => $this->invitation->family->name,
            ]))
            ->line(__('This invitation expires in seven days.'))
            ->action(__('View invitation'), route('family-invitations.show', $this->token));
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'family_id' => $this->invitation->family_id,
            'invitation_id' => $this->invitation->id,
        ];
    }
}
