<div class="onglets">
    <a href="{{ route('admin.doublons.index') }}" class="{{ request()->routeIs('admin.doublons.index') ? 'actif' : '' }}">Doublons probables</a>
    <a href="{{ route('admin.doublons.orphelins') }}" class="{{ request()->routeIs('admin.doublons.orphelins') ? 'actif' : '' }}">Brouillons orphelins</a>
</div>
