package com.copragym.mobile

import org.json.JSONArray
import org.json.JSONObject
import java.io.BufferedInputStream
import java.io.BufferedReader
import java.io.InputStreamReader
import java.net.HttpURLConnection
import java.net.URL
import java.net.URLEncoder

object PortalApi {
    data class PortalNotification(
        val id: Int,
        val title: String,
        val message: String,
        val createdAt: String,
    )

    data class PortalState(
        val ok: Boolean,
        val appName: String,
        val logoUrl: String,
        val memberFound: Boolean,
        val memberPhone: String,
        val branchId: Int,
        val latestNotificationId: Int,
        val notifications: List<PortalNotification>,
    )

    fun fetchState(phone: String, branchId: Int, afterId: Int): PortalState? {
        val query = buildString {
            append("branch_id=")
            append(branchId.coerceAtLeast(0))
            append("&")
            append("phone=")
            append(URLEncoder.encode(phone, Charsets.UTF_8.name()))
            append("&after_id=")
            append(afterId.coerceAtLeast(0))
        }
        val url = URL("${BuildConfig.PORTAL_API_URL}?$query")
        val connection = (url.openConnection() as HttpURLConnection).apply {
            requestMethod = "GET"
            connectTimeout = 15000
            readTimeout = 15000
            doInput = true
        }

        return try {
            val statusCode = connection.responseCode
            val stream = if (statusCode in 200..299) connection.inputStream else connection.errorStream
                ?: return null
            val body = BufferedReader(InputStreamReader(BufferedInputStream(stream), Charsets.UTF_8)).use { reader ->
                reader.readText()
            }
            parseState(body)
        } finally {
            connection.disconnect()
        }
    }

    private fun parseState(body: String): PortalState? {
        val json = JSONObject(body)
        val notificationsJson = json.optJSONArray("notifications") ?: JSONArray()
        val notifications = buildList {
            for (index in 0 until notificationsJson.length()) {
                val item = notificationsJson.optJSONObject(index) ?: continue
                add(
                    PortalNotification(
                        id = item.optInt("id"),
                        title = item.optString("title"),
                        message = item.optString("message"),
                        createdAt = item.optString("created_at"),
                    )
                )
            }
        }

        return PortalState(
            ok = json.optBoolean("ok"),
            appName = json.optString("app_name"),
            logoUrl = json.optString("logo_url"),
            memberFound = json.optBoolean("member_found"),
            memberPhone = json.optString("member_phone"),
            branchId = json.optInt("branch_id"),
            latestNotificationId = json.optInt("latest_notification_id"),
            notifications = notifications,
        )
    }
}
