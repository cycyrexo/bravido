import 'package:flutter/material.dart';
import 'package:flutter_map/flutter_map.dart';
import 'package:latlong2/latlong.dart';

import '../app.dart';

/// Carte OpenStreetMap (gratuite) centrée sur un point.
class CartePoint extends StatelessWidget {
  const CartePoint({super.key, required this.lat, required this.lng, this.hauteur = 200, this.couleur = Couleurs.vert});

  final double lat;
  final double lng;
  final double hauteur;
  final Color couleur;

  @override
  Widget build(BuildContext context) {
    final point = LatLng(lat, lng);
    return SizedBox(
      height: hauteur,
      child: ClipRRect(
        borderRadius: BorderRadius.circular(16),
        child: FlutterMap(
          key: ValueKey('$lat,$lng'),
          options: MapOptions(initialCenter: point, initialZoom: 16),
          children: [
            TileLayer(
              urlTemplate: 'https://tile.openstreetmap.org/{z}/{x}/{y}.png',
              userAgentPackageName: 'ci.bravido.app',
            ),
            MarkerLayer(markers: [
              Marker(point: point, width: 44, height: 44, child: Icon(Icons.location_pin, size: 44, color: couleur)),
            ]),
          ],
        ),
      ),
    );
  }
}
