/**
 * WP-Deploy-Javascript
 * @author Adrian7
 * @version 0.3
 */

var wpdAuthKey = null;

var wpdCmdUpdatesTimer = null;
var wpdBaseURL                 = "wp-deploy.php";
var wpdCurrentCMD           = null;

var wpdCurrentCMDFinished     = false;
var wpdCurrentCMDSuccessful  = false;

var wpdStatusUpdateFreq = 1000;

var wpdCMDAjax = null;

function isset_var(value){
    return ( ( value !== undefined ) && ( typeof value != 'undefined' ) && ( value != null) );
}

function wpdInit(baseURL){
    wpdBaseURL = baseURL;
}

function wpSetAuthKey(akey){
    wpdAuthKey = akey;
}

function wpdUpdateConsole(message){
    if( message !== undefined ) if( ( typeof message.text != 'undefined' ) && (message.text != null) && ( message.text != '' ) ) {

        var msgType = message.type;
        var msgText = message.text;

        var moment = new Date();
        var reqTime = ( moment.getMinutes().toString() + ':' +  moment.getSeconds().toString() );

        var element = ( '<p class="msg ' + msgType + '">' + msgText + '</p>');

        $(element).appendTo('.console');

        var scrollHeight = $('.cmd-console-wrap')[0].scrollHeight; $('.cmd-console-wrap').scrollTop( scrollHeight );
    }
}

function wpdUpdateProgress(value){
    if( value == 0 ) return;
    if( isNaN(value) ) return;

    $('#cmdProgress').val(value);
    //$('#cmdProgressBar').css({backgroundSize:( value + '% 100%')});
}

function wpdUpdateCommandStatus(status){ console.log('function wpdUpdateCommandStatus()'); console.log(status);

    var msg, progress, state = null;

    if( ( typeof status != 'undefined' ) && (status != null) && ( status != '' ) ){

        //--- parse messages ---//
        if( ( typeof status.messages != 'undefined' ) && (status.messages != null) && ( status.messages != '' ) && ( status.messages.length > 0 ) ) for(i in status.messages){
           var msg = status.messages[i]; wpdUpdateConsole(msg);
        }
        //--- parse messages ---//

        //--- parse data ---//
        if( ( typeof status.data != 'undefined' ) && (status.data != null) && ( status.data != '' ) ){
            var progress = parseInt(status.data.progress);
            var state      = status.data.state;
        }
        //--- parse data ---//

        //--- check if it is successful ---//
        if( ( typeof status.success != 'undefined' ) && (status.success != null) && ( status.success != '' ) && (status.success == true) ){
            wpdCurrentCMDSuccessful = true;
            wpdCurrentCMDFinished    = true;

            progress = 100;
        }
        //--- check if it is successful ---//

        //--- check if it is finished ---//
        if( state == 'finished' ) wpdCurrentCMDFinished = true;
        //--- check if it is finished ---//

        wpdUpdateProgress(progress);

    }
}

function wpdCommandUpdates(){

    var url = ( wpdBaseURL + '?ajax=1&cmd=' + wpdCurrentCMD + '&status=1&ak=' + wpdAuthKey);

    $.get(url, function(data){
        wpdUpdateCommandStatus(data); if( !wpdCurrentCMDFinished ) wpdCmdUpdatesTimer = setTimeout('wpdCommandUpdates()', wpdStatusUpdateFreq);
    }, "json");

}

function wpdProcessCommandResponse(response){ console.log('function: wpdProcessCommandResponse()'); console.log(response);

    clearTimeout(wpdCmdUpdatesTimer); wpdCmdUpdatesTimer = null;

    wpdCurrentCMDFinished = true; console.log(response.data); console.log('wpdCurrentCMDFinished='. wpdCurrentCMDFinished);

    //---- process successive commands ---//
    if( isset_var(response.data.nextcmd) )
        wpdTriggerCommand(response.data.nextcmd, response.data);
    //---- process successive commands ---//

    //--- process 'reload' commands ---//
    if( isset_var(response.data.reload) && ( response.data.reload == true ) )
        window.location.reload();
    //--- process 'reload' commands ---//

    //--- process 'urlload' commands ---//
    if( isset_var(response.data.urlload) )
        window.location.href = response.data.urlload;
    //--- process 'urlload' commands ---//

    //--- get 'cmdmessage' if any ---//
    if( isset_var(response.data.cmdmessage) ) console.log(response.data.cmdmessage);
    //--- get 'cmdmessage' if any ---//

    wpdCmdEndActions();
}

function wpdCmdEndActions(){
    $('#btnActionContinue').attr('disabled', false);
    $('#btnActionBack').attr('disabled', false);
    $('#btnActionReload').attr('disabled', false);

    $('#btnActionAbort').attr('disabled', true);

    if( wpdCurrentCMDSuccessful )
        $('#cmdProgressBar').addClass('cmd-successful');
    else
        $('#cmdProgressBar').addClass('cmd-failed');

}

function wpdTriggerCommand(cmd, data){ console.log("Command data:"); console.log(wpdAuthKey);

    wpdCurrentCMD = cmd; wpdCurrentCMDFinished = false; wpdCurrentCMDSuccessful = false; var url = ( wpdBaseURL + '?ajax=1&cmd=' + cmd + '&execute=yes&ak=' + wpdAuthKey);

    var defaultStatus = {messages:[{type: 'note', text: 'Started ' + cmd}], data:{progress:1}}; wpdUpdateCommandStatus(defaultStatus);

    wpdCMDAjax = $.post(url, data, function(response){
        wpdProcessCommandResponse(response);
    }, "json");

    wpdCmdUpdatesTimer = setTimeout('wpdCommandUpdates()', wpdStatusUpdateFreq);
}

function wpdAbortCommand(){
    if( confirm('Are you sure you want to abort the current action?') ){
        wpdCMDAjax.abort(); wpdCurrentCMDSuccessful = false; clearTimeout(wpdCmdUpdatesTimer); wpdCmdUpdatesTimer = null; window.location.href= wpdBaseURL;
    }
}

function wpdActionContinue(next_url){
    return window.location.href= next_url;
}

function wpdActionReload(){
    return $('#cmdRetryForm').submit();
}

function wpdActionGoBack(){
    return window.history.go(-1);
}

function wpdShowInfo(elemId){
    $(elemId).slideToggle('fast'); $(elemId).removeClass('hidden');
}

//---- document ready functions ---//
$(document).ready(function(){

    $.ajaxSetup({type: "POST", async: true, error: function (xhr, ajaxOptions, thrownError) {

        //--- a 500 error indicates a script timeout ---//
        if( xhr.status == 500 )
            msgText = "The current action has been timed out by the server. This might be due to a slow upload speed.";
        else
            msgText = ( 'Server error ' + xhr.status + ': ' + thrownError );

        var errorStatus = {messages:[{type: 'error', text: msgText}], data:{progress:1}};

        wpdUpdateCommandStatus(errorStatus);

        wpdCmdEndActions();

    }});

    $('#deployment_type').change(function(){
        if( $(this).val() == 'update' ){
            $('#db-connection-settings').hide();
        }
        else{
            $('#db-connection-settings').show();
        }
    });

    $('#connection_type').change(function(){
        if( ( $(this).val() == 'ftp' ) || ( $(this).val() == 'ftpes' ) )
            $('#label-ftp-passive').show();
        else
            $('#label-ftp-passive').hide();
    });

    $('#custom_port').click(function(){
        if( $(this).is(':checked') )
            $('.control-group-port').removeClass('hidden');
        else
            $('.control-group-port').addClass('hidden');
    });

});
//---- document ready functions ---//
