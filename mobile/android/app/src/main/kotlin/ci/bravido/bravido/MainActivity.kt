package ci.bravido.bravido

import android.app.NotificationChannel
import android.app.NotificationManager
import android.media.AudioAttributes
import android.media.RingtoneManager
import android.os.Build
import android.os.Bundle
import io.flutter.embedding.android.FlutterActivity

class MainActivity : FlutterActivity() {

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        creerCanauxNotification()
    }

    /**
     * Canaux Android (API 26+) :
     * - "alertes" : alerte d'un proche, importance maximale (bannière, son d'alarme, vibration) ;
     * - "bravido" : autres messages (réponses du support, invitations...), canal par défaut FCM.
     */
    private fun creerCanauxNotification() {
        if (Build.VERSION.SDK_INT < Build.VERSION_CODES.O) return
        val manager = getSystemService(NotificationManager::class.java) ?: return

        val alertes = NotificationChannel("alertes", "Alertes de mes proches", NotificationManager.IMPORTANCE_HIGH).apply {
            description = "Un proche a déclenché une alerte BRAVIDO"
            enableVibration(true)
            vibrationPattern = longArrayOf(0, 600, 300, 600, 300, 600)
            enableLights(true)
            lockscreenVisibility = android.app.Notification.VISIBILITY_PUBLIC
            setSound(
                RingtoneManager.getDefaultUri(RingtoneManager.TYPE_ALARM),
                AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_ALARM)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SONIFICATION)
                    .build()
            )
        }

        val general = NotificationChannel("bravido", "Informations BRAVIDO", NotificationManager.IMPORTANCE_HIGH).apply {
            description = "Invitations, réponses du support, suivi des alertes"
        }

        manager.createNotificationChannel(alertes)
        manager.createNotificationChannel(general)
    }
}
