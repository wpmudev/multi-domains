# Multi Domains

**INACTIVE NOTICE: This plugin is unsupported by WPMUDEV, we've published it here for those technical types who might want to fork and maintain it for their needs.**

## Translations

Translation files can be found at https://github.com/wpmudev/translations

## Multi-Domains adds the power to operate multiple primary domain names on a single WordPress Multisite network.

It's like having multiple networks on one Multisite installation – but better. 

![multi-domain735x470](https://premium.wpmudev.org/wp-content/uploads/2010/09/multi-domain735x470-583x373.jpg)

 Offer relevant URLs to more users on one network.

### More Network URLs to Choose From

Give users the ability to choose a network domain that relates to their business, site or service or let users easily create a niche blog and select a content relevant URL. Add full domain control and flexibility without creating a new network.

### Global Login Sync

Enable single sign-on sync and pass from site to site without having to login everytime you change domains. Provide fast user access to all the content on your network. 

### More Name Options

Automated page configuration and a powerful single-settings page have Multi-Domains working perfectly out-of-the-box. Add domains using the domain manager and the new host URL options will be automatically added to the Site Sign-up form.

![users-choose-735x470](https://premium.wpmudev.org/wp-content/uploads/2010/09/users-choose-735x470-583x373.jpg)

 Give users more choices and add value to your network.

### Level-Up With Domain Mapping and New Blog Template

Integrate Multi-Domains with [Domain Mapping](http://premium.wpmudev.org/project/domain-mapping/) and offer your users a completely custom url. Plus, with [New Blog Templates](http://premium.wpmudev.org/project/new-blog-template/ "New Blog Templates") you can set unique default themes for each host domain.

## Usage

**Please note:** Multi-Domains is for sub domain installations only and will not work well for subdirectory installations. Other important notes:

*   As of Multi-Domains version 1.3, it is no longer necessary to manually install the plugin in the mu-plugins folder. Woot! If you have an older version in that folder now, please remove it.
*   It is also no longer necessary to manually update the sunrise.php file as that is now done automatically. :)
*   Finally, if you are also using our Domain Mapping plugin, be sure that it is at least version 4.0.3.

### To Get Started

Start by reading [Installing plugins](../wpmu-manual/installing-regular-plugins-on-wpmu/) section in our comprehensive [WordPress and WordPress Multisite Manual](https://premium.wpmudev.org/manuals/) if you are new to WordPress. Once installed and network-activated, you will see a new menu item in the Network Settings menu. 

![Multi-Domains Menu](https://premium.wpmudev.org/wp-content/uploads/2010/09/multi-domains-1310-menu.png)

#### Configuring the Settings

The first thing you need to do is add a constant to your _wp-config.php_ file. Please copy the sunrise.php from the plugin folder /FOLDER-PATH/wp-content/plugins/multi-domains/sunrise.php into /FOLDER-PATH/wp-content/sunrise.php Then open your _wp-config.php_ file (normally located in the root of your install) and please uncomment or add (if not available) the following code just before the line that says "That's all, stop editing!": `define( 'SUNRISE', 'on' );` 

![multi-domains-1300-wpconfig](https://premium.wpmudev.org/wp-content/uploads/2010/09/multi-domains-1300-wpconfig.png)

 Please ensure that you type this and not just copy and paste. Take care to use single quotes and not back-ticks. Save and re-upload that file. Now head over to the Multi-Domains page in your Network Admin area at Settings > Multi-Domains 

![Multi-Domains Settings](https://premium.wpmudev.org/wp-content/uploads/2010/09/multi-domains-1310-settings.png)

 There, you can add all the domain names you want to include in the selection for new sites in your network. Simply enter the name, select Public or Private, and click Add Domain. Done. Note that domains set to Private will be available to the network admins only. Domains set to Public will be displayed on your user and blog registration pages. Bonus! If you have the [New Blog Templates](https://premium.wpmudev.org/project/new-blog-template/ "WordPress New Blog Templates Plugin - WPMU DEV") plugin installed, you can also select which template to use for each domain. Cool huh? The final step is domain name configuration in your hosting to make sure the blogs created work.

### Domain Configuration

In the DNS records for each domain added, add a wildcard subdomain that points to the IP of your WordPress multisite. You simply need to add an "A" Record by entering an asterisk for the name, and the IP of your multisite for the address. For example, here's a domain configured with a wildcard subdomain through cPanel: 

![multi-domains-1300-wildcard](https://premium.wpmudev.org/wp-content/uploads/2010/09/multi-domains-1300-wildcard.png)

### Deleting Previously Added Domains:

Hover your mouse pointer over the domain name in the list and click the Delete link which appears. 

![Multi-Domains Delete](https://premium.wpmudev.org/wp-content/uploads/2010/09/multi-domains-1310-delete.png)

 If you want to do a batch delete of several domains, check the boxes next to the domains names and click the Delete button .

### Changing a domain status:

You may want to change the status (public or private) of a domain name. 1\. Hover the domain name in the list and click the Edit link which appears. 2\. Select a new status. - Choose Public if you want it to be available to all the users who register on your blog. - Choose Private if you want it to be used by Super Admins only. 3\. Click Save Domain.

### Creating Sites in the Admin

Now when you go to Sites > Add New in your network admin, you'll see a new option to select which domain you want to add the new site to. 

![Multi-Domains Add Site](https://premium.wpmudev.org/wp-content/uploads/2010/09/multi-domains-1310-add-site.png)

### User Experience

Users who wish to create a blog in your network can now select from the domains you have made available to them when they sign up. 

![Multi-Domains Signup](https://premium.wpmudev.org/wp-content/uploads/2010/09/multi-domains-1310-signup-2.png)

### Potential Issues

Many times you may run into trouble where mapped domains don't resolve to your WordPress Multi-site install even though the DNS is correct for the domain you are trying to map. This is especially common with shared hosting. Some symptoms are getting a default or non-existent domain screen branded by your host. What this means is that your WordPress install/virtualhost is not set as the default for your IP address, so different domains do not load it up. Here is a very simple way to check if your hosting is configured correctly: Simply enter your server's IP address into a web browser and see if it loads up your WordPress signup page. For example, using the Edublogs IP you would enter http://66.135.63.39 into the web browser. See how it loads up the signup page? If entering your IP pulls up an error screen from your host (Example: http://74.54.219.243) here is what you can do: 1\. Purchase a dedicated IP address for your hosting. 2\. Many times just the dedicated IP will do the trick. If not, you will need to ask your host to configure your WPMU virtualhost to be the default for your dedicated IP. 3\. If you have addon domains in your hosting account, this may cause additional issues. To resolve this, you could add the domain you want to offer as a parked domain. Then create a wildcard A record in your DNS zone editor and point it to your dedicated IP as shown above.

*   Note that any DNS edits may take up to 48 hours to propagate.
