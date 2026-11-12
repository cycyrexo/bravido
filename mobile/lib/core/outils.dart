import 'dart:io';

import 'package:geolocator/geolocator.dart';
import 'package:intl/intl.dart';
import 'package:url_launcher/url_launcher.dart';

/// Géolocalisation tolérante : ne lève jamais d'exception, renvoie null si indisponible.
class Localisation {
  static Position? derniere;

  static Future<Position?> position({bool demander = true, Duration delai = const Duration(seconds: 10)}) async {
    try {
      if (!await Geolocator.isLocationServiceEnabled()) {
        return derniere ?? await Geolocator.getLastKnownPosition();
      }
      var permission = await Geolocator.checkPermission();
      if (permission == LocationPermission.denied && demander) {
        permission = await Geolocator.requestPermission();
      }
      if (permission == LocationPermission.denied || permission == LocationPermission.deniedForever) {
        return null;
      }
      derniere = await Geolocator.getCurrentPosition(
        locationSettings: LocationSettings(accuracy: LocationAccuracy.high, timeLimit: delai),
      );
      return derniere;
    } catch (_) {
      try {
        return derniere ?? await Geolocator.getLastKnownPosition();
      } catch (_) {
        return null;
      }
    }
  }
}

Future<void> appeler(String numero) async {
  await launchUrl(Uri(scheme: 'tel', path: numero.replaceAll(' ', '')));
}

Future<void> itineraire(num lat, num lng) async {
  await launchUrl(
    Uri.parse('https://www.google.com/maps/dir/?api=1&destination=$lat,$lng'),
    mode: LaunchMode.externalApplication,
  );
}

/// Position approximative : recherche Google Maps par nom et localité plutôt qu'un itinéraire GPS.
Future<void> rechercherSurCarte(String requete) async {
  await launchUrl(
    Uri.https('www.google.com', '/maps/search/', {'api': '1', 'query': requete}),
    mode: LaunchMode.externalApplication,
  );
}

Future<void> ecrireEmail(String adresse) async {
  await launchUrl(Uri(scheme: 'mailto', path: adresse.trim()));
}

Future<void> ouvrirLien(String url) async {
  await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
}

/// Ouvre l'application SMS pré-remplie (secours hors ligne de l'alerte).
Future<bool> composerSms(List<String> numeros, String corps) async {
  final destinataires = numeros.join(',');
  final texte = Uri.encodeComponent(corps);
  final uri = Platform.isIOS
      ? Uri.parse('sms:/open?addresses=$destinataires&body=$texte')
      : Uri.parse('sms:$destinataires?body=$texte');
  return launchUrl(uri);
}

String formatDistance(num? metres) {
  if (metres == null) return '';
  if (metres < 1000) return '${metres.round()} m';
  return '${(metres / 1000).toStringAsFixed(1).replaceAll('.', ',')} km';
}

String formatDate(String? iso) {
  if (iso == null) return '';
  final d = DateTime.tryParse(iso);
  return d == null ? iso : DateFormat('dd/MM/yyyy').format(d.toLocal());
}

String formatHeure(String? iso) {
  if (iso == null) return '';
  final d = DateTime.tryParse(iso);
  return d == null ? '' : DateFormat('HH:mm').format(d.toLocal());
}
