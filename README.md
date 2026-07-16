# SMTPPai
Free Wordpress SMTP Plugin with Amazon SES & Others
Free forever, multi-route SMTP plugin to connect 15+ email providers including Amazon SES API, Resend, Postmark, Google Workplace, Microsoft 365, Sparkpost, Gmail, SendGrid etc. Fix broken WordPress emails and route WooCommerce, newsletters, or admin alerts through separate secure senders.

Integrate Email Providers Native API (including Amazon SES)
Specify Connections for Separate task (e.g. woocommerce, wp, newsetter)
Fallback/Backup Connections

Free WordPress SMTP Plugin for Emails
Hosts often block PHP mail(), so WordPress stops delivering password resets, admin notices, and other wp_mail() messages. SMTPPai is a free WordPress SMTP plugin that routes site mail through your own providers instead. Add a connection, send a test email, and your first ready connection auto-assigns to One for Everything, so WordPress mail starts using authenticated delivery without a paid tier.

Amazon SES API, SendGrid, Mailgun, Gmail & 15+ mailers
Connect Amazon SES with the native SES API (IAM keys—not SMTP-only), or use SendGrid, Mailgun, Postmark, Resend, Brevo, SparkPost, Mailjet, Microsoft 365/Outlook, Google Workspace/Gmail, Zoho via Other SMTP, and more. Prefer HTTPS API mailers when SMTP ports are blocked; use OAuth or classic SMTP when you need it. Unlimited connections stay free—AWS/your provider still bill their own usage.

Multi-route SMTP: WordPress, WooCommerce, Newsletters on separate providers
SMTPPai’s Specify Connections maps mail types to different providers: WordPress emails, WooCommerce emails, plus newsletter and outreach routes ready for campaign plugins. Keep transactional store mail on Amazon SES and admin mail on Gmail, for example—so WooCommerce order emails don’t share the same sending path as other traffic. Use One for Everything until you need per-route control.

Backup SMTP Failover with WordPress Email Log & Retry
If the primary connection fails, SMTPPai retries through a backup provider automatically. The email log shows sent/failed status and server responses so you can fix auth errors, rejected senders, and delivery failures fast—then resend from wp-admin. Built for sites that need reliable WordPress and WooCommerce mail without guessing what the provider returned.

Gmail & Microsoft 365 OAuth for Local, Staging & Live WordPress
Connect Gmail or Microsoft 365 with your own Google Cloud or Entra app. SMTPPai’s fixed HTTPS OAuth relay helps local and staging sites complete sign-in without a public production redirect URL in the provider console. Tokens stay on your WordPress server; API keys can stay encrypted in the database or in wp-config.php. No usage telemetry and no MailPai account required for core SMTP delivery.

Visit Plugin Site: https://mailpai.com/smtp-pai/
