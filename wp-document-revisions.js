jQuery(document).ready(function(a){function c(a){window.webkitNotifications.checkPermission()>0?window.webkitNotifications.RequestPermission(c):window.webkitNotifications.createNotification(wp_document_revisions.lostLockNoticeLogo,wp_document_revisions.lostLockNoticeTitle,a).show()}a(".revision").click(function(a){a.preventDefault();if(confirm(wp_document_revisions.restoreConfirmation))window.location.href=jQuery(this).attr("href")});a("#override_link").click(function(){jQuery.post(ajaxurl,{action:"override_lock",
post_id:jQuery("#post_ID").val()||0},function(b){b?(a("#lock_override").hide(),a(".error").not("#lock-notice").hide(),a("#publish, #add_media, #lock-notice").fadeIn(),autosave()):alert(wp_document_revisions.lockError)})});a("#document a").click(function(){window.webkitNotifications&&window.webkitNotifications.requestPermission()});if(adminpage&&(adminpage=="post-php"||adminpage=="post-new-php")&&typenow&&typenow=="document")hasUpload=!1,jQuery(":button, :submit","#submitpost").prop("disabled",!0),
wp_document_revisions_autosave_enable_buttons=autosave_enable_buttons,autosave_enable_buttons=function(){a(document).trigger("autosaveComplete");hasUpload&&wp_document_revisions_autosave_enable_buttons()};a(document).bind("autosaveComplete",function(){if(a("#autosave-alert").length>0&&a("#lock-notice").length>0&&a("#lock-notice").is(":visible"))wp_document_revisions.lostLockNotice=wp_document_revisions.lostLockNotice.replace("%s",a("#title").val()),window.webkitNotifications?c(wp_document_revisions.lostLockNotice):
alert(wp_document_revisions.lostLockNotice),location.reload(!0)});a("#misc-publishing-actions a").click(function(){a(":button, :submit","#submitpost").removeAttr("disabled")});a("input, select, textarea").live("change",function(){a(":button, :submit","#submitpost").removeAttr("disabled")});a(document).bind("documentUpload",function(){postDocumentUpload(attachmentID,extension)});setInterval("updateTimestamps()",6E4)});
function human_time_diff(a,c){d=new Date;c=c||d.getTime()/1E3+parseInt(wp_document_revisions.offset);diff=Math.abs(c-a);if(diff<=3600)return mins=Math.floor(diff/60),mins<=1&&(mins=1),mins==1?wp_document_revisions.minute.replace("%d",mins):wp_document_revisions.minutes.replace("%d",mins);else if(diff<=86400&&diff>3600)return hours=Math.floor(diff/3600),hours<=1&&(hours=1),hours==1?wp_document_revisions.hour.replace("%d",hours):wp_document_revisions.hours.replace("%d",hours);else if(diff>=86400)return days=
Math.floor(diff/86400),days<=1&&(days=1),days==1?wp_document_revisions.day.replace("%d",days):wp_document_revisions.days.replace("%d",days)}function updateTimestamps(){jQuery(".timestamp").each(function(){jQuery(this).text(human_time_diff(jQuery(this).attr("id")))})}
function postDocumentUpload(a,c){a instanceof Object&&a.name.split(".").pop();var b=window.dialogArguments||opener||parent||top;if(!b.hasUpload){b.jQuery("#content").val(c);b.jQuery("#message").hide();b.jQuery("#revision-summary").show();b.jQuery(":button, :submit","#submitpost").removeAttr("disabled");b.hasUpload=!0;b.tb_remove();if(typeof convertEntities=="function")wp_document_revisions.postUploadNotice=convertEntities(wp_document_revisions.postUploadNotice);b.jQuery("#post").before(wp_document_revisions.postUploadNotice).prev().fadeIn().fadeOut().fadeIn();
b.jQuery("#sample-permalink").length!=0&&b.jQuery("#sample-permalink").html(b.jQuery("#sample-permalink").html().replace(/\<\/span>(\.[a-z0-9]{3,4})?$/i,wp_document_revisions.extension))}};