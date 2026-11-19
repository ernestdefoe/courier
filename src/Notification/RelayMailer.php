<?php

namespace Ernestdefoe\Courier\Notification;

use Ernestdefoe\Courier\Relay\RelayClient;
use Flarum\Http\UrlGenerator;
use Flarum\Locale\TranslatorInterface;
use Flarum\Notification\Blueprint\BlueprintInterface;
use Flarum\Notification\MailableInterface;
use Flarum\Notification\UnsubscribeToken;
use Flarum\Post\CommentPost;
use Flarum\Settings\SettingsRepositoryInterface;
use Flarum\User\User;

/**
 * Hands one notification for one member to the relay, which emails it with a
 * reply address that comes back to this forum.
 *
 * Built like core's NotificationMailer: in the member's own language, with the
 * same unsubscribe and settings links every Flarum notification email carries.
 */
class RelayMailer
{
    public function __construct(
        private RelayClient $relay,
        private TranslatorInterface $translator,
        private SettingsRepositoryInterface $settings,
        private UrlGenerator $url
    ) {
    }

    /** False when the relay did not send it, so the caller sends it the ordinary way. */
    public function send(MailableInterface&BlueprintInterface $blueprint, User $user, CommentPost $post): bool
    {
        // Checked first: building the body saves an unsubscribe token, and
        // ordinary mail would only save another one.
        if (! $this->relay->available()) {
            return false;
        }

        $this->translator->setLocale($user->getPreference('locale') ?? $this->settings->get('default_locale'));

        $result = $this->relay->post('mail/notify', [
            'userId' => (int) $user->id,
            'discussionId' => (int) $post->discussion_id,
            'postId' => (int) $post->id,
            'subject' => $blueprint->getEmailSubject($this->translator),
            'body' => $this->body($blueprint, $user, $post),
            'to' => ['address' => $user->email, 'name' => $user->display_name],
        ]);

        return (bool) ($result['sent'] ?? false);
    }

    /**
     * 🚨 Plain text, and it says what to do. A member who does not realise they
     * can simply reply will not, and the feature they are paying for goes
     * unused — so the instruction is in the message, not only in the docs.
     */
    private function body(BlueprintInterface $blueprint, User $user, CommentPost $post): string
    {
        $t = fn (string $key, array $params = []) => $this->translator->trans('ernestdefoe-courier.email.'.$key, $params);

        $content = trim(strip_tags((string) $post->content));

        if (mb_strlen($content) > 1500) {
            $content = mb_substr($content, 0, 1500).'…';
        }

        $unsubscribe = UnsubscribeToken::generate((int) $user->id, $blueprint::getType());
        $unsubscribe->save();

        $forum = $this->url->to('forum');

        return $t('wrote', ['author' => $post->user->display_name ?? $t('someone')])."\n\n".$content
            ."\n\n---\n".$t('reply_hint')
            ."\n\n".$t('unsubscribe', ['url' => $forum->route('notifications.unsubscribe', ['userId' => $user->id, 'token' => $unsubscribe->token])])
            ."\n".$t('settings', ['url' => $forum->route('settings')]);
    }
}
