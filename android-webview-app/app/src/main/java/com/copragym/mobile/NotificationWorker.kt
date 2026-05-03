package com.copragym.mobile

import android.content.Context
import androidx.work.Worker
import androidx.work.WorkerParameters

class NotificationWorker(
    appContext: Context,
    workerParams: WorkerParameters,
) : Worker(appContext, workerParams) {

    override fun doWork(): Result {
        val lastPhone = AppPreferences.getLastMemberPhone(applicationContext)
        val lastBranchId = AppPreferences.getLastBranchId(applicationContext)
        if (lastPhone.isBlank() || lastBranchId <= 0) {
            return Result.success()
        }

        val portalState = runCatching {
            PortalApi.fetchState(lastPhone, lastBranchId, AppPreferences.getLastNotificationId(applicationContext))
        }.getOrNull() ?: return Result.retry()

        if (!portalState.ok) {
            return Result.retry()
        }

        if (!portalState.memberFound) {
            AppPreferences.clearLastMemberPhone(applicationContext)
            AppPreferences.saveLastNotificationId(applicationContext, 0)
            return Result.success()
        }

        AppPreferences.saveLastMemberPhone(applicationContext, portalState.memberPhone)
        AppPreferences.saveLastBranchId(applicationContext, portalState.branchId)
        AppPreferences.saveAppName(applicationContext, portalState.appName)
        AppPreferences.saveLogoUrl(applicationContext, portalState.logoUrl)
        AppNotificationManager.ensureChannel(applicationContext)

        portalState.notifications
            .sortedBy { it.id }
            .forEach { notification ->
                AppNotificationManager.showPortalNotification(
                    context = applicationContext,
                    appName = portalState.appName.ifBlank { AppPreferences.getAppName(applicationContext) },
                    logoUrl = portalState.logoUrl.ifBlank { AppPreferences.getLogoUrl(applicationContext) },
                    notification = notification,
                )
            }

        AppPreferences.saveLastNotificationId(applicationContext, portalState.latestNotificationId)
        return Result.success()
    }
}
