# WooCommerce Lipa Na MPESA Payment Gateway

A nice friendly WordPress plugin that adds "Lipa na MPESA" (see kopokopo.com) as a payment gateway to WooCommerce.

To set up instant payment notifications, do the following:

1. Go to KopoKopo API settings and get your API key.  Put this in the API field inside WordPress on the Lipa na MPESA settings page.

1. Set up the "HTTP(S) POST Configuration" on the API page at KopoKopo to be as such

API: versionv3

Notification URL: http://example.com/?KOPOKOPO_IPN_LISTENER=1

Username: doesntmatter

Password: doesntmatterputanything

Replace *example.com* with your own domain name.

Please note that sometimes even after setting up as above, it still does not work.  In such cases I just reinstalled WordPress (I havent yet got time to hunt down this bug).

With the above done, orders paid for via Lipa na MPESA will automatically be marked as paid.
