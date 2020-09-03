{extends "designs/site.tpl"}

{block "content"}
    <h2>Log in to {$.Site.title|escape}</h2>
    {if $authException}
        <div class="notify error">
            <strong>Sorry!</strong> {$authException->getMessage()}
        </div>
    {elseif $error}
        <div class="notify error">
            <strong>Sorry!</strong> {$error}
        </div>
    {/if}

    {$returnUrl = cat('?return=', escape(default($.request.return, $.server.REQUEST_URI), url))}

    {$localLoginFlow = tif($.request.login_flow === 'local', true, false)}
    {if $localLoginFlow}
        {$formAttribs = ''}
    {else}
        {$formAttribs = 'action="/connectors/network-hub/login"'}
    {/if}

    <form method="POST" class="login-form" {$formAttribs}>
        {foreach item=value key=name from=$postVars}
            {if is_array($value)}
                {foreach item=subvalue key=subkey from=$value}
                <input type="hidden" name="{$name|escape}[{$subkey|escape}]" value="{$subvalue|escape}">
            {else}
                <input type="hidden" name="{$name|escape}" value="{$value|escape}">
            {/if}
        {/foreach}

        <input type="hidden" name="_LOGIN[returnMethod]" value="{refill field=_LOGIN.returnMethod default=$.server.REQUEST_METHOD}">
        <input type="hidden" name="_LOGIN[return]" value="{refill field=_LOGIN.return default=$.server.REQUEST_URI}">

        <fieldset class="shrink">
            {if $localLoginFlow}
                {loginField}
                {passwordField}
            {else}
                {field
                    inputName=email
                    label="Email Address"
                    required=true
                    attribs='autofocus autocapitalize="none" autocorrect="off" spellcheck="false"'
                    hint='Log in with your SLATE email address.'
                    default=$.request.email
                }

                {if !empty($Schools)}
                    <select name="SchoolHandle">
                        <option value="">Select One</option>
                        {foreach from=$Schools item=School}
                            <option value="{$School->Handle}">{$School->Domain}</option>
                        {/foreach}
                    </select>
                {/if}
            {/if}

            <div class="submit-area">
                <input type="submit" class="button submit" value="Log In">
                {if $localLoginFlow && RegistrationRequestHandler::$enableRegistration}
                    <span class="submit-text">or <a href="/register{tif $.request.return || $.server.SCRIPT_NAME != '/login' ? $returnUrl}">Register</a></span>
                {/if}
            </div>
        </fieldset>
    </form>
{/block}
