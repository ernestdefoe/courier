# Courier

**Reply by email for Flarum.** A member gets a notification, hits reply in
whatever mail client they already use, and their reply appears on the forum.

No mail server to run, no DNS records to add, no SMTP credentials to keep
working. Notifications are delivered for you, so they arrive even on a forum
whose own mail is misconfigured — which is most of the reason replies go
missing in the first place.

## How it works

1. Someone replies to a discussion your member is watching.
2. Courier asks the service to send that member a notification, with a reply
   address unique to them and that discussion.
3. They reply from their inbox. The quoted history and their signature are
   stripped, and what they actually typed is posted under their own account.

Their reply is a post like any other — it notifies other participants, is
indexed by search, and is screened by whatever moderation you run.

## What it will not do

- **Post from a forged sender.** Who a reply is from is decided by the token in
  the address we issued, never by the `From:` header, which anyone can set to
  anything.
- **Post on behalf of someone who has lost permission.** Permissions are
  re-checked when the reply lands, not assumed from the fact we emailed them.
  An email round-trip is long enough for a member to be suspended.
- **Post auto-replies.** Out-of-office messages, bounces, mailing-list traffic
  and delivery reports are dropped. Otherwise an auto-responder answers a
  notification, the reply becomes a post, the post sends a notification, and a
  forum fills overnight.
- **Lose a notification when the service is down.** Anything the service cannot
  take is sent the ordinary way instead. Those members lose the ability to reply
  by email for that one message, which is far better than never hearing about it.

## Install

```bash
composer require ernestdefoe/courier
```

Enable it and paste your site key. Nothing else to configure.

Requires **Flarum 2.0** and PHP 8.3+. A subscription is required:
<https://ernestdefoe.online/account>

## Support

- **Support site:** [ernestdefoe.online](https://ernestdefoe.online)
- **Issues:** [github.com/ernestdefoe/courier/issues](https://github.com/ernestdefoe/courier/issues)

## License

Proprietary — commercial license. © 2026 ernestdefoe.
