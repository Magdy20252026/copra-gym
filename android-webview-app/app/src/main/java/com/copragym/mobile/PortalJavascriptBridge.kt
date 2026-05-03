package com.copragym.mobile

import android.content.Context
import android.media.MediaScannerConnection
import android.os.Environment
import android.webkit.JavascriptInterface
import android.widget.Toast
import java.io.File
import java.io.FileOutputStream
import java.util.Base64

class PortalJavascriptBridge(
    private val context: Context,
) {
    @JavascriptInterface
    fun saveLastMemberPhone(phone: String) {
        AppPreferences.saveLastMemberPhone(context, phone)
        scheduleNotifications()
    }

    @JavascriptInterface
    fun saveLastBranchId(branchId: Int) {
        AppPreferences.saveLastBranchId(context, branchId)
        scheduleNotifications()
    }

    @JavascriptInterface
    fun clearLastMemberPhone() {
        AppPreferences.clearLastMemberPhone(context)
    }

    @JavascriptInterface
    fun clearLastBranchId() {
        AppPreferences.clearLastBranchId(context)
    }

    @JavascriptInterface
    fun downloadBase64File(dataUrl: String, filename: String, mimeType: String) {
        runCatching {
            val encodedData = dataUrl.substringAfter(',', dataUrl)
            val bytes = if (android.os.Build.VERSION.SDK_INT >= android.os.Build.VERSION_CODES.O) {
                Base64.getDecoder().decode(encodedData)
            } else {
                android.util.Base64.decode(encodedData, android.util.Base64.DEFAULT)
            }
            val safeName = filename.ifBlank { "download.png" }
            val targetDir = context.getExternalFilesDir(Environment.DIRECTORY_DOWNLOADS) ?: context.filesDir
            val targetFile = File(targetDir, safeName)
            FileOutputStream(targetFile).use { outputStream ->
                outputStream.write(bytes)
            }
            MediaScannerConnection.scanFile(context, arrayOf(targetFile.absolutePath), arrayOf(mimeType.ifBlank { "image/png" }), null)
            targetFile
        }.onSuccess { file ->
            Toast.makeText(context, context.getString(R.string.download_saved_message, file.absolutePath), Toast.LENGTH_LONG).show()
        }.onFailure {
            Toast.makeText(context, R.string.download_failed_message, Toast.LENGTH_LONG).show()
        }
    }

    private fun scheduleNotifications() {
        NotificationScheduler.scheduleImmediate(context)
        NotificationScheduler.schedulePeriodic(context)
    }
}
