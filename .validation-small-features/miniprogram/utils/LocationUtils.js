 
const toRad = (d) => { return d * Math.PI / 180; }
/**
 * 计算坐标距离
 * 输出格式：5.1km
 */
const calDistance = (locationA, locationB) => {
  let lat1 = locationA.latitude;
  let lng1 = locationA.longitude;
  let lat2 = locationB.latitude;
  let lng2 = locationB.longitude;
  let dis = 0;
  let radLat1 = toRad(lat1);
  let radLat2 = toRad(lat2);
  let deltaLat = radLat1 - radLat2;
  let deltaLng = toRad(lng1) - toRad(lng2);
  dis = 2 * Math.asin(Math.sqrt(Math.pow(Math.sin(deltaLat / 2), 2) + Math.cos(radLat1) * Math.cos(radLat2) * Math.pow(Math.sin(deltaLng / 2), 2)));
  dis = dis * 6378137;
  if (dis < 1000){
    dis = dis.toFixed(0) + 'm';
  } else if (dis < 10000){
    dis = (dis / 1000).toFixed(1) + 'km';
  } else if (dis < 100000){
    dis = (dis / 1000).toFixed(0) + 'km';
  }else{
    dis = '>99km'
  }
  return dis;
}

module.exports = {
  calDistance: calDistance
}
