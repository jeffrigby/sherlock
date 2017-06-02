function outputTable(simple) {
  var table = '';
  $.each(simple, function(k, v) {
    var id = guidGenerator();
    table += '<h4><a role="button" data-toggle="collapse" href="#'+ id + '" aria-expanded="false" aria-controls="'+ id + '">' + k + '</a></h4>';
    table += '<div class="collapse in" id="' + id + '">';
    table += '<table class="table"><thead><tr><th>Tag</th><th>Score</th></tr></thead><tbody>';

    $.each(v, function(k2, v2) {
      table += '<tr>';
      table += '<td><a href="/meta?q=' + v2.tag + '">' + v2.tag + '</a></td>';
      table += '<td>' + v2.score + '</td>';
      table += '</tr>';
    });
    table += '</tbody></table>';
    table += '</div>';
  });
  return table;
}

function guidGenerator() {
  var S4 = function() {
    return (((1+Math.random())*0x10000)|0).toString(16).substring(1);
  };
  return (S4()+S4()+"-"+S4()+"-"+S4()+"-"+S4()+"-"+S4()+S4()+S4());
}
