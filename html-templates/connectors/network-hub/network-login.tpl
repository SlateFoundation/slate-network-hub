{extends "designs/site.tpl"}

{block "title"}Slate Network Hub Log In &mdash; {$dwoo.parent}{/block}

{block "user-tools"}{/block} {* redundant *}

{block "content"}
    <header class="page-header">
        <h1 class="header-title title-1">Log in to {$siteConfig.label|default:$.server.HTTP_HOST}</h1>
    </header>

    <form method="POST" class="login-form">
        {if $authException}
            <div class="notify error">
                <strong>Sorry!</strong> {$authException->getMessage()}
            </div>
        {elseif $error}
            <div class="notify error">
                <strong>Sorry!</strong> {$error}
            </div>
        {/if}

        {foreach item=value key=name from=$postVars}
            {if is_array($value)}
                {foreach item=subvalue key=subkey from=$value}
                <input type="hidden" name="{$name|escape}[{$subkey|escape}]" value="{$subvalue|escape}">
            {else}
                <input type="hidden" name="{$name|escape}" value="{$value|escape}">
            {/if}
        {/foreach}

        <input type="hidden" name="_LOGIN[returnMethod]" value="{refill field=_LOGIN.returnMethod default=$.server.REQUEST_METHOD}">
        <input type="hidden" name="_LOGIN[return]" value="{refill field=_LOGIN.return default=$.server.REQUEST_URI}">

        <fieldset class="shrink">
            {field inputName=email label=Email required=true attribs='autofocus autocapitalize="none" autocorrect="off" spellcheck="false"' hint='Please log in with your slate email address.'}

            <div class="submit-area">
                <button type="submit">Continue</button>
            </div>
        </fieldset>
    </form>
{/block}