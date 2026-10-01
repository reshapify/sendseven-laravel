# SendSeven

This app talks to SendSeven (WhatsApp, SMS, email, Telegram, Messenger, Instagram, RCS) through `reshapify/sendseven-laravel`.

- Use the `SendSeven` facade or inject `Reshapify\SendSeven\Client`; never call the SendSeven API with `Http::` directly.
- Receive webhooks with `Route::sendSevenWebhooks()` and listen for the SDK's typed events (`MessageReceived`, `MessageStatusUpdated`, `ChannelConnected`…); never verify signatures by hand.
- Connect customer channels (including WhatsApp Embedded Signup) with `SendSeven::connect(ConnectLink::for(...))`.
- In tests use `SendSeven::fake([...])` and the `InteractsWithSendSevenWebhooks` trait.
- Activate the `sendseven-development` skill for anything involving SendSeven.
