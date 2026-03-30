<?php

namespace App\Config;

class RemoteMcpServerCatalog
{
    /**
     * Get the catalog of verified Remote MCP servers with OAuth2 DCR support.
     * All servers have been verified via live probing of /.well-known/oauth-authorization-server
     * and Dynamic Client Registration endpoints.
     *
     * @return array<string, array{name: string, url: string, logo: string}>
     */
    public static function getServers(): array
    {
        return [
            'amplitude' => ['name' => 'Amplitude', 'url' => 'https://mcp.amplitude.com/mcp', 'logo' => 'amplitude.jpg'],
            'apollo' => ['name' => 'Apollo.io', 'url' => 'https://mcp.apollo.io/mcp', 'logo' => 'apollo-io.svg'],
            'atlassian' => ['name' => 'Atlassian Rovo', 'url' => 'https://mcp.atlassian.com/v1/mcp', 'logo' => 'atlassian-rovo.jpg'],
            'attio' => ['name' => 'Attio', 'url' => 'https://mcp.attio.com/mcp', 'logo' => 'attio.svg'],
            'candid' => ['name' => 'Candid', 'url' => 'https://mcp.candid.org/mcp', 'logo' => 'candid.jpg'],
            'canva' => ['name' => 'Canva', 'url' => 'https://mcp.canva.com/mcp', 'logo' => 'canva.jpg'],
            'cb_insights' => ['name' => 'CB Insights', 'url' => 'https://mcp.cbinsights.com/mcp', 'logo' => 'cb-insights.png'],
            'close' => ['name' => 'Close', 'url' => 'https://mcp.close.com/mcp', 'logo' => 'close.jpg'],
            'cloudflare' => ['name' => 'Cloudflare', 'url' => 'https://mcp.cloudflare.com/mcp', 'logo' => 'cloudflare.jpg'],
            'common_room' => ['name' => 'Common Room', 'url' => 'https://mcp.commonroom.io/mcp', 'logo' => 'common-room.svg'],
            'consensus' => ['name' => 'Consensus', 'url' => 'https://mcp.consensus.app/mcp', 'logo' => 'consensus.svg'],
            'context7' => ['name' => 'Context7', 'url' => 'https://mcp.context7.com/mcp', 'logo' => 'context7.png'],
            'crossbeam' => ['name' => 'Crossbeam', 'url' => 'https://mcp.crossbeam.com/mcp', 'logo' => 'crossbeam.svg'],
            'daloopa' => ['name' => 'Daloopa', 'url' => 'https://mcp.daloopa.com/mcp', 'logo' => 'daloopa.svg'],
            'day_ai' => ['name' => 'Day AI', 'url' => 'https://day.ai/api/mcp', 'logo' => 'day-ai.png'],
            'factset' => ['name' => 'FactSet', 'url' => 'https://mcp.factset.com/mcp', 'logo' => 'factset-ai-ready-data.png'],
            'fellow' => ['name' => 'Fellow.ai', 'url' => 'https://fellow.app/mcp/mcp', 'logo' => 'fellow-ai.svg'],
            'figma' => ['name' => 'Figma', 'url' => 'https://mcp.figma.com/mcp', 'logo' => 'figma.jpg'],
            'granola' => ['name' => 'Granola', 'url' => 'https://mcp.granola.ai/mcp', 'logo' => 'granola.svg'],
            'huggingface' => ['name' => 'Hugging Face', 'url' => 'https://huggingface.co/mcp', 'logo' => 'hugging-face.jpg'],
            'intercom' => ['name' => 'Intercom', 'url' => 'https://mcp.intercom.com/mcp', 'logo' => 'intercom.jpg'],
            'jam' => ['name' => 'Jam', 'url' => 'https://mcp.jam.dev/mcp', 'logo' => 'jam.jpg'],
            'jotform' => ['name' => 'Jotform', 'url' => 'https://mcp.jotform.com/mcp', 'logo' => 'jotform.jpg'],
            'klaviyo' => ['name' => 'Klaviyo', 'url' => 'https://mcp.klaviyo.com/mcp', 'logo' => 'klaviyo.png'],
            'krisp' => ['name' => 'Krisp', 'url' => 'https://mcp.krisp.ai/mcp', 'logo' => 'krisp.svg'],
            'lilt' => ['name' => 'LILT', 'url' => 'https://mcp.lilt.com/mcp', 'logo' => 'lilt.png'],
            'linear' => ['name' => 'Linear', 'url' => 'https://mcp.linear.app/mcp', 'logo' => 'linear.jpg'],
            'local_falcon' => ['name' => 'Local Falcon', 'url' => 'https://mcp.localfalcon.com/mcp', 'logo' => 'local-falcon.svg'],
            'lumin' => ['name' => 'Lumin', 'url' => 'https://mcp.luminpdf.com/mcp', 'logo' => 'lumin.svg'],
            'mailerlite' => ['name' => 'MailerLite', 'url' => 'https://mcp.mailerlite.com/mcp', 'logo' => 'mailerlite.jpg'],
            'make' => ['name' => 'Make', 'url' => 'https://mcp.make.com/mcp', 'logo' => 'make.svg'],
            'mem' => ['name' => 'Mem', 'url' => 'https://mcp.mem.ai/mcp', 'logo' => 'mem.svg'],
            'mercury' => ['name' => 'Mercury', 'url' => 'https://mcp.mercury.com/mcp', 'logo' => 'mercury.svg'],
            'miro' => ['name' => 'Miro', 'url' => 'https://mcp.miro.com/mcp', 'logo' => 'miro.svg'],
            'mixpanel' => ['name' => 'Mixpanel', 'url' => 'https://mcp.mixpanel.com/mcp', 'logo' => 'mixpanel.svg'],
            'monday' => ['name' => 'monday.com', 'url' => 'https://mcp.monday.com/mcp', 'logo' => 'monday.jpg'],
            'morningstar' => ['name' => 'Morningstar', 'url' => 'https://mcp.morningstar.com/mcp', 'logo' => 'morningstar.jpg'],
            'msci' => ['name' => 'MSCI', 'url' => 'https://mcp.msci.com/mcp', 'logo' => 'msci.png'],
            'mt_newswires' => ['name' => 'MT Newswires', 'url' => 'https://mcp.mtnewswires.com/mcp', 'logo' => 'mt-newswires.jpg'],
            'notion' => ['name' => 'Notion', 'url' => 'https://mcp.notion.com/mcp', 'logo' => 'notion.jpg'],
            'paypal' => ['name' => 'PayPal', 'url' => 'https://mcp.paypal.com/mcp', 'logo' => 'paypal.jpg'],
            'posthog' => ['name' => 'PostHog', 'url' => 'https://mcp.posthog.com/mcp', 'logo' => 'posthog.svg'],
            'postman' => ['name' => 'Postman', 'url' => 'https://mcp.postman.com/mcp', 'logo' => 'postman.png'],
            'pylon' => ['name' => 'Pylon', 'url' => 'https://mcp.usepylon.com/mcp', 'logo' => 'pylon.svg'],
            'quartr' => ['name' => 'Quartr', 'url' => 'https://mcp.quartr.com/mcp', 'logo' => 'quartr.png'],
            'ramp' => ['name' => 'Ramp', 'url' => 'https://mcp.ramp.com/mcp', 'logo' => 'ramp.jpg'],
            'razorpay' => ['name' => 'Razorpay', 'url' => 'https://mcp.razorpay.com/mcp', 'logo' => 'razorpay.jpg'],
            'sanity' => ['name' => 'Sanity', 'url' => 'https://mcp.sanity.io/mcp', 'logo' => 'sanity.svg'],
            'sentry' => ['name' => 'Sentry', 'url' => 'https://mcp.sentry.dev/mcp', 'logo' => 'sentry.jpg'],
            'square' => ['name' => 'Square', 'url' => 'https://mcp.squareup.com/mcp', 'logo' => 'square.jpg'],
            'stripe' => ['name' => 'Stripe', 'url' => 'https://mcp.stripe.com/', 'logo' => 'stripe.jpg'],
            'supermetrics' => ['name' => 'Supermetrics', 'url' => 'https://mcp.supermetrics.com/mcp', 'logo' => 'supermetrics.svg'],
            'synapse' => ['name' => 'Synapse.org', 'url' => 'https://mcp.synapse.org/mcp', 'logo' => 'synapse-org.jpg'],
            'tavily' => ['name' => 'Tavily', 'url' => 'https://mcp.tavily.com/mcp', 'logo' => 'tavily.svg'],
            'vercel' => ['name' => 'Vercel', 'url' => 'https://mcp.vercel.com/', 'logo' => 'vercel.jpg'],
            'webflow' => ['name' => 'Webflow', 'url' => 'https://mcp.webflow.com/mcp', 'logo' => 'webflow.svg'],
            'windsor' => ['name' => 'Windsor.ai', 'url' => 'https://mcp.windsor.ai/mcp', 'logo' => 'windsor-ai.png'],
            'wix' => ['name' => 'Wix', 'url' => 'https://mcp.wix.com/mcp', 'logo' => 'wix.png'],
            'zapier' => ['name' => 'Zapier', 'url' => 'https://mcp.zapier.com/api/mcp/mcp', 'logo' => 'zapier.jpg'],
            'zoominfo' => ['name' => 'ZoomInfo', 'url' => 'https://mcp.zoominfo.com/mcp', 'logo' => 'zoominfo.jpg'],
        ];
    }
}
