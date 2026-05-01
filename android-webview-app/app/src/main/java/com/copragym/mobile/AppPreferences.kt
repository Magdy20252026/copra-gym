package com.copragym.mobile

import android.content.Context

object AppPreferences {
    private const val PREFS_NAME = "copra_gym_mobile"
    private const val KEY_LAST_MEMBER_PHONE = "last_member_phone"
    private const val KEY_LAST_NOTIFICATION_ID = "last_notification_id"
    private const val KEY_APP_NAME = "app_name"
    private const val KEY_LOGO_URL = "logo_url"

    private fun prefs(context: Context) = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

    fun getLastMemberPhone(context: Context): String = prefs(context).getString(KEY_LAST_MEMBER_PHONE, "") ?: ""

    fun saveLastMemberPhone(context: Context, phone: String) {
        val normalizedPhone = phone.trim()
        val currentPhone = getLastMemberPhone(context)
        val editor = prefs(context).edit().putString(KEY_LAST_MEMBER_PHONE, normalizedPhone)
        if (currentPhone != normalizedPhone) {
            editor.putInt(KEY_LAST_NOTIFICATION_ID, 0)
        }
        editor.apply()
    }

    fun clearLastMemberPhone(context: Context) {
        prefs(context).edit()
            .remove(KEY_LAST_MEMBER_PHONE)
            .putInt(KEY_LAST_NOTIFICATION_ID, 0)
            .apply()
    }

    fun getLastNotificationId(context: Context): Int = prefs(context).getInt(KEY_LAST_NOTIFICATION_ID, 0)

    fun saveLastNotificationId(context: Context, notificationId: Int) {
        prefs(context).edit().putInt(KEY_LAST_NOTIFICATION_ID, notificationId.coerceAtLeast(0)).apply()
    }

    fun getAppName(context: Context): String = prefs(context).getString(KEY_APP_NAME, context.getString(R.string.app_name)) ?: context.getString(R.string.app_name)

    fun saveAppName(context: Context, appName: String) {
        if (appName.isBlank()) return
        prefs(context).edit().putString(KEY_APP_NAME, appName).apply()
    }

    fun getLogoUrl(context: Context): String = prefs(context).getString(KEY_LOGO_URL, "") ?: ""

    fun saveLogoUrl(context: Context, logoUrl: String) {
        prefs(context).edit().putString(KEY_LOGO_URL, logoUrl.trim()).apply()
    }
}
