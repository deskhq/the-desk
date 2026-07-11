@props(['url'])
<tr>
<td class="header">
<a href="{{ $url }}">
<span class="brand-mark">&#9670;</span><span class="brand-name">{{ $slot }}</span>
</a>
</td>
</tr>
